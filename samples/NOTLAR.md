# hdmac.cloudtroid.org örnek sayfaları (2026-10-02)

İndirme: `curl -sS -L`, tarayıcı User-Agent, `Accept-Language: tr-TR`. Maç/oynatıcı sayfalarında Referer verildi. Hiçbir dosya 5 MB'ı aşmadı.

| Dosya | Kaynak URL | HTTP | Son URL | Boyut (bayt) |
|---|---|---|---|---|
| home.html | https://hdmac.cloudtroid.org/ | 200 | aynı | 71075 |
| page2.html | https://hdmac.cloudtroid.org/page/2/ | 200 | aynı | 71294 |
| amp.html | https://hdmac.cloudtroid.org/amp/ | 200 | aynı | 132827 |
| archive_today.html | https://hdmac.cloudtroid.org/2026/10/02/ | **404** (WP "Sayfa bulunamadı") | aynı | 37582 |
| archive_today_page2.html | https://hdmac.cloudtroid.org/2026/10/02/page/2/ | **404** | aynı | 37672 |
| sitemap.xml | https://hdmac.cloudtroid.org/post-sitemap.xml | 200 (325 `<url>`) | aynı | 88140 |
| match1.html | https://cloudtroid.org/2026/09/18/pafos-ael-justintv-izle-18-eylul-2026/ | 200 | aynı | 43648 |
| match2.html | https://cloudtroid.org/2026/09/18/als-omonia-aek-larnaca-justintv-izle-18-eylul-2026/ | 200 | aynı | 44195 |
| match3.html | https://cloudtroid.org/2026/09/18/rapid-wien-wsg-tirol-justintv-izle-18-eylul-2026/ | 200 | aynı | 44109 |
| player1.html | https://jyayin6.vip/cytavisionsport1 | **404** | aynı | 35 |
| player2.html | https://jyayin6.vip/cablenetsport | **404** | aynı | 32 |
| player3.html | https://jyayin6.vip/skysportaus | **404** | aynı | 30 |
| player_extra_spor_watch_TEST_cloudflare403.html | https://jyayin6.vip/spor/watch/TEST | **403** (Cloudflare "Just a moment...") | aynı | 5747 |

Notlar:
- `/sitemap.xml` ve `/wp-sitemap.xml` → 200 ama `https://cloudtroid.org/sitemap.xml`'e yönleniyor (All in One SEO sitemap index; `post-sitemap.xml`, `addl-sitemap.xml` ...). Ayrı dosya olarak kaydedilmedi; sadece `post-sitemap.xml` kaydedildi.
- Bugünün gün arşivi (`/2026/10/02/`) yok: sitedeki en yeni yazılar 2026-09-18 tarihli (başlıkları 18 Eylül ve 19 Ekim 2026 maçları). Arşivler `/YYYY/MM/DD/` ve `/YYYY/MM/` (ör. `/2026/09/`) kalıbında; var olan gün için `/YYYY/MM/DD/page/N/`.
- hdmac.cloudtroid.org sayfalarındaki tüm linkler canonical alan adı `cloudtroid.org`'a işaret eder; maç sayfaları bu yüzden cloudtroid.org'dan indirildi (Referer: ana sayfa). AMP sayfasında linkler `hdmac-cloudtroid-org.cdn.ampproject.org/c/s/hdmac.cloudtroid.org/.../amp/?c=1` biçimindedir.
- Oynatıcı alan adı `jyayin6.vip`: tüm `/<kanal>` adresleri bu oturumda `404 "Kanal bulunamadı: <kanal>"` döndü (kanal yalnızca maç saatinde aktif olabilir ya da Referer/IP kontrolü olabilir; kök `/` → "Cannot GET /"). Bu yüzden iç iframe (`player1_inner.html`) ve m3u8 örneği alınamadı. Denenen diğer kanallar: bluesport1, hum4sport, frbeinsports1, digisport, espn1, espn2 → hepsi 404. `19 Ekim 2026` yazılarının iframe'i `https://jyayin6.vip/spor/watch/TEST` (Cloudflare 403 challenge, yer tutucu "TEST").

## HTML yapısı (WordPress, tema mh-magazine-lite, ana sayfa özel şablon)

Maç satırı (home/page2, 50 satır/sayfa):
```html
<div class="list">
  <a href="https://cloudtroid.org/YYYY/MM/DD/<slug>-justintv-izle-<gün>-<ay>-<yıl>/" target="_blank">
    <div class="list-title">
      <div class="shape"></div>
      <div class="playicon"><img src="https://cloudtroid.org/bayrak/<kod>.png" width="20" alt=""></div>
      <div class="inon">
        <div class="time">03:15</div>
        <div class="title">Racing Club - Sarmiento justintv izle 19 Ekim 2026</div>
        <div class="play"><i class="fa fa-play"></i></div>
      </div>
    </div>
  </a>
</div>
```
- Saat: `.time`; başlık: `.title` ("<Takım A> - <Takım B> justintv izle <tarih>"); ikon/bayrak: `.playicon img` (`/bayrak/xxx.png`, ülke/lig kodu); kategori ana listede yok (maç sayfasında `span.entry-meta-categories a[rel="category tag"]`, örn. `/category/futbol/`).
- AMP sayfasında aynı `.list` yapısı, `amp-img` ile ve tab paneli (`role="tabpanel"`) içinde.
- Yan menü: widget "Son yazılar" (`<ul><li><a>`), arşiv widget'ı (`/2026/09/`, `/2026/01/`).
- Sayfalama: `<link rel="next" href="https://cloudtroid.org/page/2/">` ve `/page/N/` kalıbı. Sitemap: `post-sitemap.xml` (`<url><loc>` yazı URL'leri).
- Maç sayfası: `div.entry-content` içinde sponsor banner'ları (jtvsponsor.com), sonra `<p>…<span id="more-ID"></span><iframe id="fra" name="I1" src="https://jyayin6.vip/<kanal>" width="100%" height="433" ...></iframe></p>`. Sayfa başına tek iframe (metin "en az 2 alternatif" dese de ek iframe/buton yok). Başlık `h1.entry-title`, tarih `.entry-meta-date`.

## Canlı yayın (m3u8) bulma yöntemi

- Ana sayfa, page2, amp ve üç maç sayfasında `m3u8` / `atob` geçmiyor (grep: 0). Yani m3u8 doğrudan HTML'de, base64'te veya satır içi JS değişkeninde yok.
- Yayın adresi yalnızca oynatıcı iframe'inde (`https://jyayin6.vip/<kanal-slug>`) olmalı; slug örnekleri: cytavisionsport1, cablenetsport, skysportaus, bluesport1-3, hum4sport, frbeinsports1, digisport, espn1/2/4. Oynatıcı sayfaları bu oturumda alınamadığı (404) için m3u8'in düz metin / atob / JS değişkeni / API çağrısı ile nasıl verildiği DOĞRULANAMADI. Maç saatinde (kanal aktifken) `player*.html` yeniden indirilip incelenmeli.
- `wp-json` uç noktası (`https://cloudtroid.org/wp-json/`) ve oEmbed linkleri sayfada var; yayın adresi içermiyor.
