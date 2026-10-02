<?php
/**
 * ============================================================================
 *  Emin Xtream Player & Tine TV - GENIS JSON AKTARICI SISTEM  (v2)
 * ----------------------------------------------------------------------------
 *  v1'e gore yenilikler (daha genis veri):
 *   1) COK KAYNAKLI TARAMA : anasayfa + /page/N/ + gun arsivleri (dun/bugun/yarin)
 *                            + AMP surumu + yedek (mirror) alan adlari + sitemap
 *   2) YENI TEMA DESTEGI   : site artik <div class="list"> yapisini kullaniyor.
 *                            Eski <li class="macyayinlinks"> yapisi da desteklenir.
 *   3) KATEGORI            : data-name="Futbol/Basketbol/Voleybol/Buz Hokeyi"
 *   4) FILTRE SERBEST      : NBA / basket / konferans ikonlari artik SILINMIYOR,
 *                            istege bagli olarak ayarlardan kapatilabiliyor.
 *   5) PARALEL INDIRME     : curl_multi ile es zamanli istek (yuzlerce sayfa hizli)
 *   6) GENIS M3U8 AVI      : main/index/playlist/master.m3u8, file:, source:, src:,
 *                            hlsUrl, JSON alanlari, base64(atob) ve ic ice iframe
 *   7) ZENGIN CIKTI        : liste.json (uyumlu) + liste_full.json (detayli)
 *                            + liste.m3u (IPTV playlist)
 *   8) TEKRAR ENGELLEME    : URL + baslik bazli dedup, retry, gzip, UA rotasyonu
 *   9) KAPANMA KORUMASI    : site kapanir/alan adi degisirse kaynak_bulucu.php
 *                            devreye girip yeni adresi bulur; olu oynatici
 *                            sunucusunu (jyayin6.vip vb.) calisanla degistirir.
 *
 *  Kullanim:
 *    Tarayici : aktarici.php?gun=2&sayfa=8&debug=1
 *    Terminal : php aktarici.php --gun=2 --sayfa=8 --limit=0
 * ============================================================================
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
@set_time_limit(0);
@ini_set('memory_limit', '512M');
if (PHP_SAPI !== 'cli') header('Content-Type: text/plain; charset=utf-8');

// =============================== AYARLAR ====================================
$AYAR = [
    // Ana kaynak + yedek alan adlari (biri olmezse digeri denenir)
    'base_url'        => 'https://hdmac.cloudtroid.org/',
    'mirror_urls'     => [
        'https://hdmac.cloudtroid.org/',
    ],

    // --- TARAMA GENISLIGI ---
    'sayfa_derinlik'  => 6,     // /page/2/ ... /page/N/  (0 = kapali)
    'gun_geri'        => 1,     // kac gun geriye arsiv taransin
    'gun_ileri'       => 1,     // kac gun ileriye arsiv taransin
    'arsiv_sayfa'     => 3,     // gun arsivlerinde kac sayfa
    'amp_tara'        => true,  // AMP surumunu de tara
    'sitemap_tara'    => false, // post-sitemap.xml (eski maclari da getirir, yavas)
    'sitemap_limit'   => 120,

    // --- FILTRE (bos birakirsan HICBIR SEY elenmez = en genis veri) ---
    'yasakli_ikonlar'   => [],  // orn: ['nba.png','bas.png']
    'yasakli_kelimeler' => [],  // orn: ['tekrar','ozet']
    'sadece_kategori'   => [],  // orn: ['Futbol'] -> sadece futbol

    // --- AG ---
    'es_zamanli'      => 12,    // paralel baglanti sayisi
    'timeout'         => 18,
    'retry'           => 2,
    'iframe_derinlik' => 2,     // ic ice iframe takip seviyesi

    // --- CIKTI ---
    'json_file'       => 'liste.json',       // Xtream/Tine uyumlu (v1 formati)
    'json_detay_file' => 'liste_full.json',  // tum meta veriler
    'm3u_file'        => 'liste.m3u',        // IPTV playlist
    'grup_modu'       => 'saat',             // saat | kategori | ikon
    'varsayilan_logo' => 'https://i.hizliresim.com/gm27zjl.png',
    'kaynaksiz_ekle'  => true,  // yayin linki cozulemese bile kayda ekle (detay json)
    'otomatik_kaynak' => true,  // site kapanirsa kaynak_bulucu.php ile yeni adresi bul
    'oynatici_onar'   => true,  // olu oynatici sunucusunu calisan bir sunucuyla degistir
    'dogrula'         => true,  // bulunan m3u8 gercekten aciliyor mu diye test et
    'sadece_canli'    => false, // true ise dogrulamayi gecemeyenler liste.json'a yazilmaz
    'limit'           => 0,     // 0 = sinirsiz
    'debug'           => false,
];

// -------- Parametre ile ayar ezme (?gun=2&sayfa=8&limit=50&debug=1) --------
$P = $_GET ?: [];
if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([\w]+)(?:=(.*))?$/', $a, $m)) $P[$m[1]] = $m[2] ?? '1';
    }
}
if (isset($P['sayfa']))  $AYAR['sayfa_derinlik'] = (int)$P['sayfa'];
if (isset($P['gun']))    $AYAR['gun_geri'] = $AYAR['gun_ileri'] = (int)$P['gun'];
if (isset($P['limit']))  $AYAR['limit'] = (int)$P['limit'];
if (isset($P['debug']))  $AYAR['debug'] = (bool)$P['debug'];
if (isset($P['amp']))    $AYAR['amp_tara'] = (bool)$P['amp'];
if (isset($P['sitemap']))$AYAR['sitemap_tara'] = (bool)$P['sitemap'];
if (isset($P['grup']))   $AYAR['grup_modu'] = $P['grup'];
if (isset($P['site'])) {   // elle adres verme:  --site=https://yeniadres.com/
    $AYAR['base_url'] = rtrim($P['site'], '/') . '/';
    $AYAR['mirror_urls'] = [$AYAR['base_url']];
}
if (isset($P['dogrula'])) $AYAR['dogrula'] = (bool)$P['dogrula'];
if (isset($P['canli']))   $AYAR['sadece_canli'] = (bool)$P['canli'];

$UA_LISTE = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    'Mozilla/5.0 (Linux; Android 13; SM-S908B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Mobile Safari/537.36',
];

function ua() { global $UA_LISTE; return $UA_LISTE[array_rand($UA_LISTE)]; }
function log_yaz($msg) { echo $msg . "\n"; @ob_flush(); @flush(); }
function dbg($msg) { global $AYAR; if ($AYAR['debug']) log_yaz("   [debug] " . $msg); }

// ============================ AG KATMANI ====================================

/** Tek bir curl handle uretir (paralel ve tekli istekler ayni ayarlari kullanir) */
function ch_yap($url, $referer = null, $timeout = null) {
    global $AYAR;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_ENCODING       => '',            // gzip/deflate/br
        CURLOPT_USERAGENT      => ua(),
        CURLOPT_TIMEOUT        => $timeout ?: $AYAR['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: tr-TR,tr;q=0.9,en;q=0.8',
            $referer ? 'Referer: ' . $referer : null,
        ]),
    ]);
    return $ch;
}

/** Tek URL indir (retry'li) */
function http_get($url, $referer = null) {
    global $AYAR;
    for ($i = 0; $i <= $AYAR['retry']; $i++) {
        $ch = ch_yap($url, $referer);
        $body = curl_exec($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        if ($body !== false && strlen($body) > 0) {
            return ['body' => $body, 'url' => $info['url'] ?? $url, 'code' => $info['http_code'] ?? 0];
        }
        usleep(300000 * ($i + 1));
    }
    return ['body' => '', 'url' => $url, 'code' => 0];
}

/** Coklu URL'i paralel indir -> [url => ['body'=>..,'url'=>efektifUrl,'code'=>..]] */
function http_get_many(array $urls, $referer = null) {
    global $AYAR;
    $urls = array_values(array_unique(array_filter($urls)));
    $sonuc = [];
    $parcalar = array_chunk($urls, max(1, (int)$AYAR['es_zamanli']));

    foreach ($parcalar as $grup) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($grup as $u) {
            $ch = ch_yap($u, $referer);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['ch' => $ch, 'url' => $u];
        }
        do {
            $st = curl_multi_exec($mh, $calisan);
            if ($calisan) curl_multi_select($mh, 0.5);
        } while ($calisan && $st == CURLM_OK);

        foreach ($handles as $h) {
            $body = curl_multi_getcontent($h['ch']);
            $info = curl_getinfo($h['ch']);
            $sonuc[$h['url']] = [
                'body' => (string)$body,
                'url'  => $info['url'] ?? $h['url'],
                'code' => $info['http_code'] ?? 0,
            ];
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
        }
        curl_multi_close($mh);
    }
    return $sonuc;
}

/**
 * m3u8 adreslerini toplu test eder.  [url => referer]  ->  [url => true/false]
 * Sadece manifest'in ilk kilobaytini ister, hizlidir.
 */
function m3u8_test_many(array $harita) {
    global $AYAR;
    $sonuc = [];
    foreach (array_chunk($harita, max(1, (int)$AYAR['es_zamanli']), true) as $grup) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($grup as $url => $ref) {
            $ch = ch_yap($url, $ref, 10);
            curl_setopt($ch, CURLOPT_RANGE, '0-2047');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: */*',
                'Referer: ' . $ref,
                'Origin: ' . rtrim($ref, '/'),
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int)$ch] = ['ch' => $ch, 'url' => $url];
        }
        do {
            $st = curl_multi_exec($mh, $calisan);
            if ($calisan) curl_multi_select($mh, 0.5);
        } while ($calisan && $st == CURLM_OK);

        foreach ($handles as $h) {
            $body = (string)curl_multi_getcontent($h['ch']);
            $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            $sonuc[$h['url']] = (($code == 200 || $code == 206) && stripos($body, '#EXTM3U') !== false);
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
        }
        curl_multi_close($mh);
    }
    return $sonuc;
}

// ========================= YARDIMCI FONKSIYONLAR ============================

function mutlak_url($url, $taban) {
    if ($url === '' || $url === null) return '';
    if (strpos($url, '//') === 0) return 'https:' . $url;
    if (preg_match('#^https?://#i', $url)) return $url;
    $p = parse_url($taban);
    $kok = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    return $kok . '/' . ltrim($url, '/');
}

function alan_adi($url) {
    $p = parse_url($url);
    if (empty($p['host'])) return '';
    return ($p['scheme'] ?? 'https') . '://' . $p['host'];
}

/** Basligi temizler: tarih, "justintv izle", tire vb. */
function baslik_temizle($ham) {
    $t = html_entity_decode(trim(strip_tags($ham)), ENT_QUOTES, 'UTF-8');
    $t = preg_replace([
        '/\d{1,2}\s+(Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül|Ekim|Kasım|Aralık)\s+\d{4}/ui',
        '/justin\s*tv\s*izle/ui',
        '/justintv\s*izle/ui',
        '/canlı\s*izle/ui',
        '/\bizle\b\s*$/ui',
    ], '', $t);
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return trim($t, " \t\n\r\0\x0B-–—|");
}

/** "Milan - Inter" -> ['Milan','Inter'] */
function takimlari_ayir($baslik) {
    if (preg_match('/^(.+?)\s+[-–—]\s+(.+)$/u', $baslik, $m)) {
        return [trim($m[1]), trim($m[2])];
    }
    return [$baslik, ''];
}

/** Bayrak/ikon dosyasindan okunabilir etiket uretir */
function ikon_etiketi($ikon) {
    $harita = [
        'soc.png' => 'Futbol', 'nba.png' => 'NBA', 'bas.png' => 'Basketbol',
        'uef.png' => 'UEFA', 'tur.png' => 'Türkiye', 'msr.png' => 'Mısır',
        'avkonf.png' => 'Avrupa Konferans', 'Futbol.png' => 'Futbol',
        'Basketbol.png' => 'Basketbol', 'Voleybol.png' => 'Voleybol',
        'Buz.png' => 'Buz Hokeyi', 'arj.png' => 'Arjantin', 'bre.png' => 'Brezilya',
        'bol.png' => 'Bolivya', 'isr.png' => 'İsrail', 'izl.png' => 'İzlanda',
        'kol.png' => 'Kolombiya', 'rom.png' => 'Romanya', 'rus.png' => 'Rusya',
        'sam.png' => 'Güney Amerika', 'uru.png' => 'Uruguay', 'ven.png' => 'Venezuela',
    ];
    $dosya = basename(parse_url($ikon, PHP_URL_PATH) ?: $ikon);
    return $harita[$dosya] ?? ucfirst(pathinfo($dosya, PATHINFO_FILENAME));
}

// ====================== 1) TARANACAK SAYFALARI URET =========================

function taranacak_sayfalar() {
    global $AYAR;
    $liste = [];

    foreach ($AYAR['mirror_urls'] as $kok) {
        $kok = rtrim($kok, '/') . '/';
        $liste[] = $kok;                                   // anasayfa
        for ($i = 2; $i <= $AYAR['sayfa_derinlik'] + 1; $i++) {
            $liste[] = $kok . "page/$i/";                   // sayfalama
        }
        if ($AYAR['amp_tara']) {
            $liste[] = $kok . 'amp/';                       // AMP surumu
        }
        // Gun arsivleri: /YYYY/MM/DD/ (+ sayfalari)
        for ($g = -abs($AYAR['gun_geri']); $g <= abs($AYAR['gun_ileri']); $g++) {
            $ts = strtotime("$g day");
            $tarih = date('Y/m/d', $ts);
            $liste[] = $kok . $tarih . '/';
            for ($s = 2; $s <= $AYAR['arsiv_sayfa'] + 1; $s++) {
                $liste[] = $kok . $tarih . "/page/$s/";
            }
        }
    }
    return array_values(array_unique($liste));
}

// ======================= 2) LISTE OGELERINI AYIKLA ==========================

/**
 * Sayfadaki kategori bloklarini (data-name="Futbol") konumlariyla dondurur.
 * Boylece her mac hangi spor dalinda oldugu bilinir.
 */
function kategori_haritasi($html) {
    $harita = [];
    if (preg_match_all('/data-name=["\']([^"\']+)["\']/i', $html, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as $k) $harita[] = ['pos' => $k[1], 'ad' => trim($k[0])];
    }
    return $harita;
}

function kategori_bul($harita, $pos) {
    $ad = '';
    foreach ($harita as $k) {
        if ($k['pos'] <= $pos) $ad = $k['ad']; else break;
    }
    return $ad;
}

/**
 * YENI TEMA: <div class="list"> ... <a href> ... playicon img ... time ... title
 * ESKI TEMA: <li> ... <a class="macyayinlinks" href> ...
 * Ikisini de destekler.
 */
function ogeleri_ayikla($html, $kaynak_url) {
    $ogeler = [];
    $katHarita = kategori_haritasi($html);

    // ---------- YENI TEMA ----------
    if (preg_match_all('/<div\s+class=["\']list["\']\s*>/i', $html, $mm, PREG_OFFSET_CAPTURE)) {
        $konumlar = array_map(fn($x) => $x[1], $mm[0]);
        foreach ($konumlar as $i => $bas) {
            $son = $konumlar[$i + 1] ?? strlen($html);
            $blok = substr($html, $bas, $son - $bas);

            if (!preg_match('/<a\s+[^>]*href=["\']([^"\']+)["\']/i', $blok, $lm)) continue;
            $link = mutlak_url($lm[1], $kaynak_url);
            if (!preg_match('#/\d{4}/\d{2}/\d{2}/#', $link)) continue; // yayin sayfasi degil

            preg_match('/<div\s+class=["\']title["\']\s*>(.*?)<\/div>/is', $blok, $tm);
            preg_match('/<div\s+class=["\']time["\']\s*>(.*?)<\/div>/is', $blok, $sm);
            preg_match('/<div\s+class=["\']playicon["\'][^>]*>.*?<img[^>]*src=["\']([^"\']+)["\']/is', $blok, $im);

            $ham = $tm[1] ?? '';
            if ($ham === '') { // baslik yoksa slug'dan uret
                $ham = ucwords(str_replace('-', ' ', basename(rtrim($link, '/'))));
            }
            $ogeler[] = [
                'link'      => $link,
                'ham'       => $ham,
                'saat'      => trim(strip_tags($sm[1] ?? '')) ?: '00:00',
                'ikon'      => $im[1] ?? '',
                'kategori'  => kategori_bul($katHarita, $bas),
                'blok'      => $blok,
            ];
        }
    }

    // ---------- ESKI TEMA (yedek) ----------
    if (!$ogeler && preg_match_all('/<li[^>]*>(.*?)<\/li>/s', $html, $li)) {
        foreach ($li[1] as $blok) {
            if (!preg_match('/<a[^>]*class="macyayinlinks"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/s', $blok, $lm)) continue;
            preg_match('/class="saat">.*?<span>(.*?)<\/span>/s', $blok, $sm);
            preg_match('/<img[^>]*src=["\']([^"\']+)["\']/i', $blok, $im);
            $ogeler[] = [
                'link'     => mutlak_url($lm[1], $kaynak_url),
                'ham'      => $lm[2],
                'saat'     => trim($sm[1] ?? '') ?: '00:00',
                'ikon'     => $im[1] ?? '',
                'kategori' => '',
                'blok'     => $blok,
            ];
        }
    }

    // ---------- SON CARE: sayfadaki tum yayin linkleri ----------
    if (!$ogeler && preg_match_all('#href=["\'](https?://[^"\']+/\d{4}/\d{2}/\d{2}/[^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $am)) {
        foreach ($am[1] as $i => $u) {
            $ogeler[] = [
                'link' => $u, 'ham' => $am[2][$i], 'saat' => '00:00',
                'ikon' => '', 'kategori' => '', 'blok' => '',
            ];
        }
    }
    return $ogeler;
}

// ==================== 3) YAYIN (M3U8) COZUMLEYICI ===========================

/**
 * JS birlestirmesinden ("...main.m3u8?_t='+Date.now()") arta kalan
 * bos/yarim query parametrelerini siler.  ?_t=  ->  (kaldirilir)
 */
function m3u8_kuyruk_temizle($url) {
    $p = parse_url($url);
    if (empty($p['query'])) return rtrim($url, '?&');
    parse_str($p['query'], $q);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    $temel = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') .
             (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '');
    return $q ? $temel . '?' . http_build_query($q) : $temel;
}

/** Bir HTML/JS icerigindeki tum olasi m3u8 adreslerini toplar */
function m3u8_avla($icerik, $taban) {
    $bulunan = [];

    // a) Duz m3u8 adresleri (tam url)
    if (preg_match_all('#(https?:)?//[^\s\'"<>\\\\]+?\.m3u8[^\s\'"<>\\\\]*#i', $icerik, $m)) {
        foreach ($m[0] as $u) $bulunan[] = $u;
    }
    // b) file: / source: / src: / hlsUrl / url: degiskenleri (goreli olabilir)
    $anahtarlar = 'file|source|src|url|hls|hlsUrl|stream|streamUrl|playlist|video';
    if (preg_match_all('/(?:' . $anahtarlar . ')\s*[:=]\s*["\']([^"\']+\.m3u8[^"\']*)["\']/i', $icerik, $m)) {
        foreach ($m[1] as $u) $bulunan[] = $u;
    }
    // c) JSON alanlari: {"file":"..."} gibi kacisli slashlar
    if (preg_match_all('#["\']((?:https?:)?\\\\?/\\\\?/[^"\']+?\.m3u8[^"\']*)["\']#i', $icerik, $m)) {
        foreach ($m[1] as $u) $bulunan[] = str_replace('\\/', '/', $u);
    }
    // d) base64 gomulu adres  atob("aHR0cHM6...")
    if (preg_match_all('/atob\(\s*["\']([A-Za-z0-9+\/=]{16,})["\']\s*\)/', $icerik, $m)) {
        foreach ($m[1] as $b64) {
            $coz = base64_decode($b64, true);
            if ($coz && stripos($coz, '.m3u8') !== false) $bulunan[] = trim($coz);
        }
    }

    $temiz = [];
    foreach ($bulunan as $u) {
        $u = trim(html_entity_decode($u), " \t\n\r\"'");
        if ($u === '' || stripos($u, '.m3u8') === false) continue;
        $u = mutlak_url($u, $taban);
        if (!preg_match('#^https?://#i', $u)) continue;
        $u = m3u8_kuyruk_temizle($u);
        $temiz[$u] = true;
    }
    $temiz = array_keys($temiz);

    // main/master/index/playlist olanlari one al (asil yayin genelde budur)
    usort($temiz, function ($a, $b) {
        $p = fn($u) => preg_match('#/(main|master|index|playlist)\.m3u8#i', $u) ? 0 : 1;
        return $p($a) <=> $p($b);
    });
    return $temiz;
}

/** Icerikteki iframe adreslerini dondurur (reklam alanlari haric) */
function iframe_avla($icerik, $taban) {
    $liste = [];
    // Once id="fra" olan asil oynatici
    if (preg_match('/<iframe[^>]*id=["\']fra["\'][^>]*src=["\']([^"\']+)["\']/i', $icerik, $m)) {
        $liste[] = mutlak_url($m[1], $taban);
    }
    if (preg_match_all('/<iframe[^>]*src=["\']([^"\']+)["\']/i', $icerik, $m)) {
        foreach ($m[1] as $u) {
            $u = mutlak_url($u, $taban);
            if (preg_match('#(doubleclick|googlesyndication|google\.com|facebook|disqus|youtube|adservice)#i', $u)) continue;
            $liste[] = $u;
        }
    }
    // JS ile atanan oynatici adresleri: fra.src = "..." / location.href="..."
    if (preg_match_all('/(?:\.src|location\.href)\s*=\s*["\']([^"\']+)["\']/i', $icerik, $m)) {
        foreach ($m[1] as $u) {
            $u = mutlak_url($u, $taban);
            if (preg_match('#^https?://#', $u) && !preg_match('#\.(js|css|png|jpe?g|gif|svg|woff2?)$#i', $u)) $liste[] = $u;
        }
    }
    return array_values(array_unique($liste));
}

/**
 * Oynatici sayfasindan yayin adresini cozer.
 * Iframe'ler ic ice olabilecegi icin belirlenen derinlige kadar takip eder.
 */
function yayin_coz($oynatici_url, $referer, $derinlik = 0) {
    global $AYAR;
    if ($derinlik > $AYAR['iframe_derinlik'] || !$oynatici_url) return null;

    $r = http_get($oynatici_url, $referer);
    $icerik   = $r['body'];
    $efektif  = $r['url'];
    $oyn_alan = alan_adi($efektif);

    // 1) Dogrudan m3u8
    $adaylar = m3u8_avla($icerik, $efektif);
    if ($adaylar) {
        return [
            'm3u8'     => $adaylar[0],
            'hepsi'    => array_slice($adaylar, 0, 5),
            'oynatici' => $efektif,
            // Tarayicinin gonderecegi referer/origin oynatici sayfasinin alan adidir
            'alan'     => $oyn_alan ?: alan_adi($adaylar[0]),
            'cdn'      => alan_adi($adaylar[0]),
            'yontem'   => 'dogrudan',
        ];
    }

    // 2) Ic iframe'leri takip et
    foreach (array_slice(iframe_avla($icerik, $efektif), 0, 3) as $ic) {
        if (rtrim($ic, '/') === rtrim($efektif, '/')) continue;
        $alt = yayin_coz($ic, $efektif, $derinlik + 1);
        if ($alt) { $alt['yontem'] = 'iframe-' . ($derinlik + 1); return $alt; }
    }

    // 3) Fallback: adres yapisindan tahmin (…/kanal  ya da  ?source=kanal)
    $p = parse_url($efektif);
    parse_str($p['query'] ?? '', $q);
    $slug = $q['source'] ?? $q['id'] ?? $q['kanal'] ?? '';
    if (!$slug) {
        $parcalar = array_values(array_filter(explode('/', trim($p['path'] ?? '', '/'))));
        $slug = end($parcalar) ?: '';
        if (in_array(strtolower($slug), ['watch', 'embed', 'player', 'index.php', ''])) {
            $slug = count($parcalar) > 1 ? $parcalar[count($parcalar) - 2] : '';
        }
    }
    // Henuz kanal atanmamis yer tutucular (TEST, yok, bos ...) gecerli yayin degildir
    if (in_array(strtoupper($slug), ['TEST', 'YOK', 'BOS', 'DEMO', 'YAKINDA'])) {
        dbg("yer tutucu kanal atlandi: $slug ($efektif)");
        return null;
    }
    if ($slug && $oyn_alan) {
        $adaylar = [
            $oyn_alan . '/' . $slug . '/main.m3u8',
            $oyn_alan . '/' . $slug . '/index.m3u8',
            $oyn_alan . '/' . $slug . '/playlist.m3u8',
        ];
        return [
            'm3u8'     => $adaylar[0],
            'hepsi'    => $adaylar,
            'oynatici' => $efektif,
            'alan'     => $oyn_alan,
            'cdn'      => $oyn_alan,
            'yontem'   => 'tahmin',
        ];
    }
    return null;
}

// ============================ 4) ANA AKIS ===================================

log_yaz("========================================================");
log_yaz(" GENIS JSON AKTARIM BASLADI  -  " . date('d.m.Y H:i:s'));
log_yaz("========================================================");

// ---------------- 0) KAYNAK KONTROLU: site ayakta mi? -----------------------
// Site kapandiysa / alan adi degistiyse kaynak_bulucu.php yenisini bulur.
$bulucu_var = $AYAR['otomatik_kaynak'] && is_file(__DIR__ . '/kaynak_bulucu.php');
if ($bulucu_var) {
    require_once __DIR__ . '/kaynak_bulucu.php';
    $on = http_get($AYAR['base_url']);
    if ($on['code'] != 200 || kb_imza_puani($on['body']) < 2) {
        log_yaz("UYARI: {$AYAR['base_url']} yanit vermiyor -> yeni adres araniyor ...");
        $yeni = kb_site_bul(true);
        if ($yeni) {
            log_yaz("Yeni kaynak adresi     : $yeni");
            $AYAR['base_url'] = $yeni;
            array_unshift($AYAR['mirror_urls'], $yeni);
            $AYAR['mirror_urls'] = array_values(array_unique($AYAR['mirror_urls']));
        } else {
            log_yaz("HATA: Calisan bir kaynak bulunamadi. kaynak_bulucu.php icindeki");
            log_yaz("      'bilinen_siteler' listesine guncel adresi elle ekleyin.");
            exit(1);
        }
    } else {
        // Adres yasiyor; canonical farkliysa guncel olani kullan
        if (preg_match('#rel=["\']canonical["\']\s+href=["\'](https?://[^/"\']+)#i', $on['body'], $cm)) {
            $kanon = rtrim($cm[1], '/') . '/';
            if (parse_url($kanon, PHP_URL_HOST) !== parse_url($AYAR['base_url'], PHP_URL_HOST)) {
                log_yaz("Site guncel adresini bildirdi: $kanon");
                $AYAR['base_url'] = $kanon;
                array_unshift($AYAR['mirror_urls'], $kanon);
            }
        }
        kb_durum_yaz(['site' => $AYAR['base_url'], 'site_zaman' => date('c'), 'site_yontem' => 'canli-kontrol']);
    }
}

$sayfalar = taranacak_sayfalar();
log_yaz("Taranacak liste sayfasi : " . count($sayfalar));

$indirilen = http_get_many($sayfalar);
$basarili = 0;
foreach ($indirilen as $r) if ($r['code'] == 200 && $r['body']) $basarili++;
log_yaz("Erisilen sayfa          : $basarili / " . count($sayfalar));

// --- Sitemap (istege bagli, eski yayinlari da katar) ---
$sitemap_linkleri = [];
if ($AYAR['sitemap_tara']) {
    $sm = http_get(rtrim($AYAR['base_url'], '/') . '/post-sitemap.xml');
    if (preg_match_all('#<loc>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</loc>#is', $sm['body'], $m)) {
        $sitemap_linkleri = array_slice(array_reverse($m[1]), 0, $AYAR['sitemap_limit']);
        log_yaz("Sitemap linki           : " . count($sitemap_linkleri));
    }
}

// --- Ogeleri topla + tekilleştir ---
$ogeler = [];
foreach ($indirilen as $kaynak => $r) {
    if (!$r['body']) continue;
    foreach (ogeleri_ayikla($r['body'], $r['url'] ?: $kaynak) as $o) {
        $anahtar = rtrim($o['link'], '/');
        if (isset($ogeler[$anahtar])) {
            // Daha zengin veri geldiyse gucellendir (saat/ikon/kategori bos kalmasin)
            foreach (['saat', 'ikon', 'kategori'] as $alan) {
                if (empty($ogeler[$anahtar][$alan]) || $ogeler[$anahtar][$alan] === '00:00') {
                    if (!empty($o[$alan])) $ogeler[$anahtar][$alan] = $o[$alan];
                }
            }
            continue;
        }
        $ogeler[$anahtar] = $o;
    }
}
foreach ($sitemap_linkleri as $u) {
    $u = trim($u);
    if (!preg_match('#/\d{4}/\d{2}/\d{2}/#', $u)) continue;
    if (isset($ogeler[rtrim($u, '/')])) continue;
    $ogeler[rtrim($u, '/')] = [
        'link' => $u, 'ham' => ucwords(str_replace('-', ' ', basename(rtrim($u, '/')))),
        'saat' => '00:00', 'ikon' => '', 'kategori' => '', 'blok' => '',
    ];
}
log_yaz("Bulunan benzersiz yayin : " . count($ogeler));

// --- Filtreler ---
$elenen = 0;
foreach ($ogeler as $k => $o) {
    $sil = false;
    foreach ($AYAR['yasakli_ikonlar'] as $ik)   if ($ik && stripos($o['blok'] . $o['ikon'], $ik) !== false) $sil = true;
    foreach ($AYAR['yasakli_kelimeler'] as $kel) if ($kel && stripos($o['ham'], $kel) !== false) $sil = true;
    if ($AYAR['sadece_kategori'] && $o['kategori'] && !in_array($o['kategori'], $AYAR['sadece_kategori'])) $sil = true;
    if ($sil) { unset($ogeler[$k]); $elenen++; }
}
if ($elenen) log_yaz("Filtreyle elenen        : $elenen");

$ogeler = array_values($ogeler);
if ($AYAR['limit'] > 0) $ogeler = array_slice($ogeler, 0, $AYAR['limit']);

// --- Yayin sayfalarini paralel indir, iframe'leri bul ---
log_yaz("\nYayin sayfalari indiriliyor (" . count($ogeler) . ") ...");
$sayfa_icerik = http_get_many(array_column($ogeler, 'link'), $AYAR['base_url']);

$json_items = [];   // v1 uyumlu cikti
$detay      = [];   // genis cikti
$ok = 0; $fail = 0;

foreach ($ogeler as $i => $o) {
    $baslik = baslik_temizle($o['ham']);
    if ($baslik === '') $baslik = 'Bilinmeyen Yayın';
    [$ev, $dep] = takimlari_ayir($baslik);

    $sayfa = $sayfa_icerik[$o['link']]['body'] ?? '';
    if (!$sayfa) { $r = http_get($o['link'], $AYAR['base_url']); $sayfa = $r['body']; }

    $iframeler = iframe_avla($sayfa, $o['link']);
    $res = null;
    foreach (array_slice($iframeler, 0, 2) as $ifr) {
        $res = yayin_coz($ifr, $o['link']);
        if ($res) break;
    }

    // Oynatici sunucusu olmusse (jyayin6.vip kapandi gibi) calisan sunucuda ayni yolu dene
    if (!$res && $iframeler && $bulucu_var && $AYAR['oynatici_onar']) {
        if (!isset($calisan_oynaticilar)) {
            $calisan_oynaticilar = kb_oynatici_bul();   // ilk ihtiyacta bir kez taranir
        }
        if ($calisan_oynaticilar && ($onarilan = kb_oynatici_onar($iframeler[0], $calisan_oynaticilar))) {
            dbg("oynatici onarildi: {$iframeler[0]} -> $onarilan");
            $res = yayin_coz($onarilan, $o['link']);
            if ($res) $res['yontem'] .= '+onarim';
        }
    }

    $etiket = $o['kategori'] ?: ikon_etiketi($o['ikon']);
    $grup = match ($AYAR['grup_modu']) {
        'kategori' => $etiket ?: 'Diğer',
        'ikon'     => ikon_etiketi($o['ikon']) ?: 'Diğer',
        default    => $o['saat'] ?: '00:00',
    };
    $logo = $o['ikon'] ? mutlak_url($o['ikon'], $o['link']) : $AYAR['varsayilan_logo'];

    $kayit = [
        'baslik'    => $baslik,
        'ev_sahibi' => $ev,
        'deplasman' => $dep,
        'saat'      => $o['saat'],
        'kategori'  => $etiket,
        'ikon'      => $logo,
        'sayfa'     => $o['link'],
        'slug'      => basename(rtrim($o['link'], '/')),
        'tarih'     => preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $o['link'], $dm) ? "$dm[3].$dm[2].$dm[1]" : date('d.m.Y'),
        'oynatici'  => $res['oynatici'] ?? '',
        'm3u8'      => $res['m3u8'] ?? '',
        'alternatif'=> $res['hepsi'] ?? [],
        'yontem'    => $res['yontem'] ?? 'yok',
        'turkce'    => (stripos($o['blok'], 'tur.png') !== false || preg_match('/\b(trt|bein|tivibu|s ?sport|smart|aspor|tv8)\b/i', $baslik)) ? 1 : 0,
    ];

    if ($res && $res['m3u8']) {
        $ok++;
        $alan = $res['alan'];
        $json_items[] = [
            'service'      => 'iptv',
            'title'        => $baslik,
            'media_url'    => $res['m3u8'],
            'url'          => $res['m3u8'],
            'h1Key'        => 'accept',      'h1Val' => '*/*',
            'h2Key'        => 'referer',     'h2Val' => $alan . '/',
            'h3Key'        => 'origin',      'h3Val' => $alan,
            'h4Key'        => 'user-agent',  'h4Val' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'thumb_square' => $AYAR['varsayilan_logo'],
            'logo'         => $logo,
            'group'        => $grup,
            'category'     => $etiket,
            'time'         => $o['saat'],
            'date'         => $kayit['tarih'],
            'home_team'    => $ev,
            'away_team'    => $dep,
            'page'         => $o['link'],
            'priority'     => $kayit['turkce'],
            'alt'          => $res['hepsi'],
        ];
        log_yaz(sprintf("  [%3d/%d] OK   %-55s %s", $i + 1, count($ogeler), mb_substr($baslik, 0, 55), $res['yontem']));
    } else {
        $fail++;
        log_yaz(sprintf("  [%3d/%d] --   %-55s kaynak yok", $i + 1, count($ogeler), mb_substr($baslik, 0, 55)));
    }

    if ($res || $AYAR['kaynaksiz_ekle']) $detay[] = $kayit;
}

// ------------------- 4.b) YAYIN ADRESLERINI DOGRULA -------------------------
$canli_sayi = 0; $olu_sayi = 0; $tamir = 0;
if ($AYAR['dogrula'] && $json_items) {
    log_yaz("\nYayin adresleri dogrulaniyor (" . count($json_items) . ") ...");

    // 1. tur: birincil adresler
    $test = [];
    foreach ($json_items as $it) $test[$it['media_url']] = $it['h2Val'];
    $sonuc = m3u8_test_many($test);

    // 2. tur: olu olanlar icin alternatif adresler (index/playlist.m3u8 vb.)
    $yedek = [];
    foreach ($json_items as $it) {
        if (!empty($sonuc[$it['media_url']])) continue;
        foreach ($it['alt'] as $a) if ($a !== $it['media_url']) $yedek[$a] = $it['h2Val'];
    }
    $yedek_sonuc = $yedek ? m3u8_test_many($yedek) : [];

    foreach ($json_items as &$it) {
        $canli = !empty($sonuc[$it['media_url']]);
        if (!$canli) {
            foreach ($it['alt'] as $a) {
                if (!empty($yedek_sonuc[$a])) {         // alternatif calisiyorsa ona gec
                    $it['media_url'] = $it['url'] = $a;
                    $canli = true; $tamir++;
                    break;
                }
            }
        }
        $it['live'] = $canli ? 1 : 0;
        $canli ? $canli_sayi++ : $olu_sayi++;
    }
    unset($it);

    // Detay listesine de canli bilgisini isle
    $durum = [];
    foreach ($json_items as $it) $durum[$it['page']] = ['live' => $it['live'], 'm3u8' => $it['media_url']];
    foreach ($detay as &$d) {
        if (isset($durum[$d['sayfa']])) { $d['canli'] = $durum[$d['sayfa']]['live']; $d['m3u8'] = $durum[$d['sayfa']]['m3u8']; }
        else $d['canli'] = 0;
    }
    unset($d);

    log_yaz("  Canli: $canli_sayi   Olu: $olu_sayi   Alternatifle kurtarilan: $tamir");

    if ($AYAR['sadece_canli']) {
        $json_items = array_values(array_filter($json_items, fn($x) => $x['live'] == 1));
        log_yaz("  'sadece_canli' aktif -> liste.json'a yazilan: " . count($json_items));
    }
}

// --- TR yayinlarini basa al, sonra saate gore sirala ---
// Sira: once calisan yayinlar, sonra TR kanallari, sonra saate gore
usort($json_items, function ($a, $b) {
    $al = $a['live'] ?? 1; $bl = $b['live'] ?? 1;
    if ($al !== $bl) return $bl - $al;
    if ($a['priority'] !== $b['priority']) return $b['priority'] - $a['priority'];
    return strcmp($a['time'], $b['time']);
});
foreach ($json_items as &$it) unset($it['priority'], $it['alt']);
unset($it);

// ============================ 5) CIKTILAR ===================================
$JSON_BAYRAK = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;

// 5.1 Oynatici uyumlu liste (v1 formati korunur)
file_put_contents($AYAR['json_file'], json_encode(['list' => ['item' => $json_items]], $JSON_BAYRAK));

// 5.2 Detayli/genis liste
file_put_contents($AYAR['json_detay_file'], json_encode([
    'meta' => [
        'olusturma'      => date('c'),
        'kaynak'         => $AYAR['base_url'],
        'taranan_sayfa'  => count($sayfalar),
        'bulunan_yayin'  => count($ogeler),
        'cozulen_yayin'  => $ok,
        'cozulemeyen'    => $fail,
        'canli_dogrulanan' => $canli_sayi,
        'olu_adres'        => $olu_sayi,
        'alternatifle_kurtarilan' => $tamir,
        'ayarlar'        => array_intersect_key($AYAR, array_flip([
            'sayfa_derinlik', 'gun_geri', 'gun_ileri', 'arsiv_sayfa',
            'amp_tara', 'sitemap_tara', 'grup_modu',
        ])),
    ],
    'items' => $detay,
], $JSON_BAYRAK));

// 5.3 M3U playlist
$m3u = "#EXTM3U\n";
foreach ($json_items as $it) {
    $m3u .= '#EXTINF:-1 tvg-name="' . $it['title'] . '" tvg-logo="' . $it['logo'] .
            '" group-title="' . $it['group'] . '",' . $it['title'] . "\n";
    $m3u .= '#EXTVLCOPT:http-referrer=' . $it['h2Val'] . "\n";
    $m3u .= '#EXTVLCOPT:http-user-agent=' . $it['h4Val'] . "\n";
    $m3u .= $it['media_url'] . "\n";
}
file_put_contents($AYAR['m3u_file'], $m3u);

log_yaz("\n========================================================");
log_yaz(" BASARILI");
log_yaz("  Taranan sayfa      : " . count($sayfalar));
log_yaz("  Bulunan yayin      : " . count($ogeler));
log_yaz("  Cozulen yayin      : $ok");
log_yaz("  Cozulemeyen        : $fail");
if ($AYAR['dogrula']) log_yaz("  Canli (test edildi): $canli_sayi   / olu: $olu_sayi   / kurtarilan: $tamir");
log_yaz("  Listeye yazilan    : " . count($json_items));
log_yaz("  Dosyalar           : {$AYAR['json_file']} , {$AYAR['json_detay_file']} , {$AYAR['m3u_file']}");
log_yaz("========================================================");
