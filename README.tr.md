# Revana

> Travian gibi klasik strateji oyunlarından ilham alınan tarayıcı tabanlı strateji oyun motoru.

**[Canlı Önizleme →](https://unkownpr.github.io/Revana/)**

![Revana Önizleme](https://i.hizliresim.com/d7o4wfa.gif)

---

**Diğer dillerde oku:**
🇬🇧 [English](./README.md) &nbsp;|&nbsp; 🇫🇷 [Français](./README.fr.md) &nbsp;|&nbsp; 🇩🇪 [Deutsch](./README.de.md)

---

Şehirler kur, ittifaklar yap, dünyayı fethet — hepsi tarayıcından. Revana, PHP 8 ve MariaDB üzerine inşa edilmiş, kendi sunucuna kurulabilir açık kaynaklı bir oyun motorudur. Herhangi bir standart web sunucusuna kurarak kendi strateji oyun topluluğunu başlatabilirsin.

---

## Özellikler

| Kategori | Detaylar |
|---|---|
| **Şehir İnşaatı** | Çok kademeli yükseltme kuyruklu 22 farklı bina türü |
| **Askeri Sistem** | 13 birlik türü (piyade, süvari, okçu, kuşatma, deniz), silah dövme, ordu yükseltme |
| **İttifaklar** | İttifak kurma, anlaşmalar, savaş ilanı, ittifak forumları |
| **Dünya Haritası** | Etkileşimli karo harita (ova, kaynak, su), şehir kurma, keşif |
| **Ekonomi** | 5 kaynak üretimi, depolama limitleri, oyuncu-oyuncuya ve NPC pazar sistemi |
| **Görevler** | Günlük ve haftalık görevler, başarım sistemi |
| **Sosyal** | Forumlar, gerçek zamanlı sohbet, özel mesajlar, savaş raporları |
| **Yönetim Paneli** | Kullanıcı yönetimi, sezon kontrolü, harita editörü, barbar kamp yönetimi, premium yapılandırma |
| **Premium** | Yapılandırılabilir paketli isteğe bağlı premium mağaza |
| **Fraksiyonlar** | 3 oynanabilir fraksiyon (İmparatorluk, Lonca, Tarikat) — her biri kendine özgü birim ve binalar |
| **Yerelleştirme** | 7 dil: İngilizce, Almanca, Fransızca, İtalyanca, Felemenkçe, Rumence, Türkçe |
| **Güvenlik** | CSRF koruması, giriş denemesi sınırı, rol tabanlı erişim kontrolü |

---

## Teknoloji Yığını

- **Arka Uç:** PHP 8.0+, [Fat-Free Framework (F3)](https://fatfreeframework.com/)
- **Veritabanı:** MariaDB / MySQL
- **Otomatik Yükleme:** PSR-4 (`Devana\` namespace → `app/`)
- **Paket Yöneticisi:** Composer
- **Ön Yüz:** Vanilla JavaScript, CSS3
- **Mimari:** MVC + Servis Katmanı

---

## Proje Yapısı

```
revana/
├── app/
│   ├── Controllers/    # 24 kontrolcü
│   ├── Models/         # 21 model + kuyruk varyantları
│   ├── Services/       # 34 iş mantığı servisi
│   ├── Middleware/     # Auth, CSRF, Admin, Install
│   ├── Enums/          # ArmyAction, MapTileType, UserRole
│   └── Helpers/        # AvatarHelper, vb.
├── ui/                 # F3 HTML şablonları
├── language/           # i18n dosyaları (en, de, fr, it, nl, ro, tr)
├── data/               # Statik oyun verileri
├── docs/               # GitHub Pages sitesi
├── default/            # Tema varlıkları (CSS, görseller)
├── index.php           # Başlatıcı
├── routes.ini          # 299 rota
├── config.ini          # Veritabanı + oyun ayarları (takip edilmiyor)
└── revana.sql          # Tam veritabanı şeması + tohum verisi
```

---

## Hızlı Başlangıç

### Gereksinimler

- PHP 8.0+
- MariaDB 10.3+ veya MySQL 8+
- Composer
- Apache/Nginx (geliştirme için PHP yerleşik sunucusu)

### Kurulum — Adım Adım

**1. Depoyu klonla**
```bash
git clone https://github.com/unkownpr/Revana.git
cd Revana
```

**2. Bağımlılıkları yükle**
```bash
composer install
```

**3. Veritabanını oluştur**
```bash
mysql -u root -p -e "CREATE DATABASE revana CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**4. Web kurulum sihirbazını çalıştır**

Tarayıcında `http://alan-adiniz/install` adresine git. Sihirbaz şunları yapacaktır:
- Sunucu gereksinimlerini kontrol eder (PHP, PDO, GD)
- Veritabanı bağlantısını kurar ve tüm tabloları oluşturur
- Admin hesabını ve ilk şehri oluşturur
- `config.ini` dosyasını yazar ve kurulumu kilitler

**5. Admin paneline giriş yap**

`/admin` adresine git ve oyun sezonunu, haritayı ve kayıt ayarlarını yapılandır.

**6. (İsteğe bağlı) Geliştirme sunucusu**
```bash
php -S localhost:8081 index.php
```

---

## Yapılandırma

`config.ini` dosyasındaki temel ayarlar (`config.example.ini` dosyasından oluştur):

```ini
[db]
host = localhost
port = 3306
name = revana
user = veritabani_kullanicisi
pass = veritabani_sifresi

[game]
title = Revana
map_size = 100
```

Çalışma zamanı oyun ayarları (yinelenen IP izni, kayıt açık/kapalı, posta yapılandırması) `config` veritabanı tablosunda saklanır ve admin paneli üzerinden yönetilebilir.

---

## Admin Paneli

Kurulumdan sonra admin kimlik bilgilerinle giriş yap ve `/admin` adresine git:

- **Kullanıcı Yönetimi** — Oyuncuları görüntüle, düzenle, kaynak ver, yasakla
- **Sezon Kontrolü** — Oyun sezonlarını oluştur, etkinleştir, bitir ve sıfırla
- **Harita Editörü** — Dünya haritasını karo karo boyat
- **Barbar Kampları** — NPC rakipler oluştur ve yapılandır
- **Premium Mağaza** — Paket ve ürünleri yapılandır
- **Bot Yönetimi** — NPC bot oyuncuları yönet
- **Oyun Ayarları** — Kayıt, yinelenen IP kuralları, posta ayarlarını değiştir

---

## Veritabanı Şeması

Tam şema `revana.sql` dosyasındadır. Temel tablolar:

| Tablo | Amaç |
|---|---|
| `users` | Oyuncu hesapları |
| `towns` | Oyuncu şehirleri (kaynaklar, binalar, ordu) |
| `buildings` | Fraksiyon başına bina tanımları |
| `units` | Fraksiyon başına birlik tanımları |
| `weapons` | Silah ve zırh tanımları |
| `factions` | 3 oynanabilir fraksiyon |
| `alliances` | İttifak verileri |
| `map` | Dünya haritası karoları |
| `c_queue` | İnşaat kuyruğu |
| `u_queue` | Birlik eğitim kuyruğu |
| `w_queue` | Silah üretim kuyruğu |
| `a_queue` | Ordu hareketi kuyruğu |
| `t_queue` | Ticaret teklifleri |
| `config` | Çalışma zamanı oyun yapılandırması |

---

## Lisans

Bu proje **Revana Oyun Motoru Lisansı** ile lisanslanmıştır.

- Kullanım ve dağıtım ücretsizdir
- Değiştirme ve yeniden dağıtım hakları yazar tarafından saklıdır
- Yazar, gelecekteki sürümler için ticari lisanslama hakkını saklı tutar

Tam koşullar için [LICENSE](./LICENSE) dosyasına bakın.

---

## Diğer Diller

- [English README](./README.md)
- [Français README](./README.fr.md)
- [Deutsch README](./README.de.md)

---

<p align="center">
  <a href="https://unkownpr.github.io/Revana/">Canlı Önizleme</a> ·
  <a href="https://github.com/unkownpr/Revana/issues">Hata Bildir</a> ·
  <a href="https://github.com/unkownpr/Revana/blob/main/LICENSE">Lisans</a>
</p>
