<?php
/**
 * =====================================================================
 *  AJAX UÇ NOKTASI (Endpoint) – Tüm işlemlerin merkezi
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  Bu dosya HTML üretmez; SADECE JSON döndürür.
 *  index.php'deki JavaScript buraya POST isteği atar, gelen JSON'a
 *  bakarak ekranı günceller.
 *
 *  DOSYA İNDİRME BURADA DEĞİLDİR: Excel çıktısı JSON olmadığı için
 *  ayrı bir uç noktadadır (system/export.php).
 *
 *  "action" parametresi hangi işlemin yapılacağını belirler:
 *  ---------------------------------------------------------------
 *    action=list            → DataTables için kayıt listesi
 *    action=add             → Yeni kayıt ekle
 *    action=edit            → Mevcut kaydı güncelle
 *    action=fetch           → Tek kaydı getir
 *    action=delete          → Kaydı sil
 *    action=import_preview  → Excel'i oku, doğrula, ÖNİZLEME döndür
 *    action=import_commit   → Onaylanan önizlemeyi veritabanına yaz
 *  ---------------------------------------------------------------
 *
 *  İÇE AKTARMA NEDEN İKİ ADIMLI?
 *  Dosya yüklenir yüklenmez kaydetmek, yanlış dosya seçen bir
 *  kullanıcının veritabanını bozmasına yol açar. Bu yüzden önce
 *  her satır doğrulanıp kullanıcıya gösterilir (import_preview),
 *  onay verilirse tek bir transaction içinde yazılır (import_commit).
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';
require __DIR__ . '/Excel/XlsxReader.php';

/* ---------------------------------------------------------------------
 *  GÜVENLİK KONTROLÜ: Sadece POST kabul edilir.
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Yalnızca POST istekleri kabul edilir.', 405);
}

$action = isset($_POST['action']) ? strtolower(trim((string) $_POST['action'])) : 'list';

try {
    switch ($action) {
        case 'add':
        case 'edit':
            handle_save($db, $action);
            break;

        case 'fetch':
            handle_fetch($db);
            break;

        case 'delete':
            handle_delete($db);
            break;

        case 'import_preview':
            handle_import_preview($db);
            break;

        case 'import_commit':
            handle_import_commit($db);
            break;

        case 'list':
        default:
            handle_list($db);
            break;
    }
} catch (PDOException $e) {
    error_log('[EXCEL] Veritabani hatasi: ' . $e->getMessage());

    json_error(
        APP_DEBUG ? 'Veritabanı hatası: ' . $e->getMessage()
                  : 'Beklenmeyen bir veritabanı hatası oluştu.',
        500
    );
} catch (RuntimeException $e) {
    /* KULLANICI HATASI, SUNUCU HATASI DEĞİL.
     *
     * ÖLÇÜLEN DURUM: Yanlışlıkla .php (ya da xlsx olmayan bir zip)
     * seçen kullanıcıya "HTTP 500 Internal Server Error" dönüyordu.
     * Oysa sunucuda bozulan hiçbir şey yok; dosya geçersiz. 500
     * görmek hem kullanıcıyı "site çökmüş" diye paniğe sokar hem de
     * izleme araçlarında gerçek arızalarla karışıp gürültü üretir.
     *
     * XlsxReader ve doğrulayıcılar geçersiz dosya için RuntimeException
     * fırlatır; bunları 422 (işlenemeyen içerik) ile yanıtlıyoruz.
     * Mesaj zaten kullanıcıya gösterilmek üzere yazılmıştır, bu
     * yüzden APP_DEBUG'a bakmadan olduğu gibi iletiyoruz. */
    error_log('[EXCEL] Gecersiz girdi: ' . $e->getMessage());

    json_error($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('[EXCEL] Hata: ' . $e->getMessage());

    json_error(
        APP_DEBUG ? 'Hata: ' . $e->getMessage() : 'Beklenmeyen bir hata oluştu.',
        500
    );
}


/* =====================================================================
 *  1) KAYIT EKLEME / GÜNCELLEME
 * ================================================================== */
function handle_save(PDO $db, string $action): void
{
    require_csrf();

    $errors = [];

    /* --- ALAN DOĞRULAMA -------------------------------------------
     * Her doğrulayıcı [temizlenmiş değer, hata] çifti döndürür.
     * Hataları tek tek toplayıp hepsini birden gösteriyoruz;
     * kullanıcıyı "bir hatayı düzelt, sonrakini gör" döngüsüne
     * sokmak kötü bir deneyimdir.
     * ----------------------------------------------------------- */
    [$name, $nameError]           = validate_name($_POST['name'] ?? '', 'Ad');
    [$surname, $surnameError]     = validate_name($_POST['surname'] ?? '', 'Soyad');
    [$email, $emailError]         = validate_email($_POST['email'] ?? '');
    [$departman, $departmanError] = validate_departman($_POST['departman'] ?? '');
    [$maas, $maasError]           = validate_maas($_POST['maas'] ?? '');
    [$baslama, $baslamaError]     = validate_tarih($_POST['baslama_tarihi'] ?? '');

    foreach ([
        'name'           => $nameError,
        'surname'        => $surnameError,
        'email'          => $emailError,
        'departman'      => $departmanError,
        'maas'           => $maasError,
        'baslama_tarihi' => $baslamaError,
    ] as $field => $message) {
        if ($message !== null) {
            $errors[$field] = $message;
        }
    }

    $isEdit  = ($action === 'edit');
    $current = null;

    if ($isEdit) {
        $id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($id === false || $id === null) {
            json_error('Geçersiz kayıt numarası.');
        }

        $current = find_user($db, $id);

        if ($current === null) {
            json_error('Güncellenecek kayıt bulunamadı.', 404);
        }
    }

    /* --- E-POSTA BENZERSİZLİĞİ -------------------------------------
     * Veritabanındaki UNIQUE indeks bunu zaten engeller; ancak o
     * durumda kullanıcı anlaşılmaz bir "SQLSTATE[23000] Duplicate
     * entry" hatası görür. Önceden kontrol edip alanın altına
     * düzgün bir mesaj yazıyoruz.
     *
     * NOT: Kontrol ile INSERT arasında (çok küçük bir ihtimalle)
     * başka biri aynı e-postayı ekleyebilir. Asıl güvence yine
     * veritabanı indeksidir; bu kontrol sadece mesajı güzelleştirir.
     * ----------------------------------------------------------- */
    if ($email !== '' && !isset($errors['email'])) {
        $excludeId = $isEdit ? (int) $current['id'] : 0;

        if (email_exists($db, $email, $excludeId)) {
            $errors['email'] = 'Bu e-posta adresi başka bir kayıtta kullanılıyor.';
        }
    }

    /* --- GÖRSEL YÜKLEME -------------------------------------------- */
    $hasNewImage = isset($_FILES['image_user'])
        && is_array($_FILES['image_user'])
        && (int) $_FILES['image_user']['error'] !== UPLOAD_ERR_NO_FILE;

    $newImage = null;

    if ($hasNewImage) {
        try {
            if ($errors === []) {
                $newImage = upload_image($_FILES['image_user']);
            } else {
                upload_image_precheck($_FILES['image_user']);
            }
        } catch (RuntimeException $e) {
            $errors['image_user'] = $e->getMessage();
        }
    }

    if ($errors !== []) {
        if ($newImage !== null) {
            delete_upload($newImage);
        }

        json_error('Lütfen formdaki hataları düzeltin.', 422, ['errors' => $errors]);
    }

    /* --- GÜNCELLEME ------------------------------------------------ */
    if ($isEdit) {
        $image = $newImage ?? (string) $current['image'];

        $stmt = $db->prepare(
            'UPDATE users SET name = :name, surname = :surname, email = :email,
                    departman = :departman, maas = :maas, baslama_tarihi = :baslama, image = :image
              WHERE id = :id'
        );

        $stmt->execute([
            ':name'      => $name,
            ':surname'   => $surname,
            ':email'     => $email,
            ':departman' => $departman,
            ':maas'      => $maas,
            ':baslama'   => $baslama,
            ':image'     => $image,
            ':id'        => $current['id'],
        ]);

        if ($newImage !== null && $current['image'] !== '') {
            delete_upload((string) $current['image']);
        }

        json_success('Kayıt başarıyla güncellendi.', ['id' => (int) $current['id']]);
    }

    /* --- YENİ KAYIT ------------------------------------------------ */
    $stmt = $db->prepare(
        'INSERT INTO users (name, surname, email, departman, maas, baslama_tarihi, image)
         VALUES (:name, :surname, :email, :departman, :maas, :baslama, :image)'
    );

    $stmt->execute([
        ':name'      => $name,
        ':surname'   => $surname,
        ':email'     => $email,
        ':departman' => $departman,
        ':maas'      => $maas,
        ':baslama'   => $baslama,
        ':image'     => $newImage ?? '',
    ]);

    json_success('Kayıt başarıyla eklendi.', ['id' => (int) $db->lastInsertId()]);
}


/**
 * Bu e-posta başka bir kayıtta kullanılıyor mu?
 *
 * @param int $excludeId Düzenlenen kaydın kendi ID'si (0 = yeni kayıt).
 *                       Kendi e-postasını "zaten kullanılıyor" saymamak
 *                       için hariç tutulur.
 */
function email_exists(PDO $db, string $email, int $excludeId = 0): bool
{
    $stmt = $db->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $stmt->execute([':email' => $email, ':id' => $excludeId]);

    return $stmt->fetchColumn() !== false;
}


/**
 * Görseli DİSKE YAZMADAN sadece doğrular.
 *
 * @param array<string,mixed> $file $_FILES['image_user']
 * @throws RuntimeException Dosya geçersizse
 */
function upload_image_precheck(array $file): void
{
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Dosya yüklenirken bir hata oluştu.');
    }

    if ((int) $file['size'] > UPLOAD_MAX_BYTES) {
        throw new RuntimeException(
            'Görsel boyutu en fazla ' . (int) (UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB olabilir.'
        );
    }

    $info = @getimagesize($file['tmp_name']);

    if ($info === false || !array_key_exists(strtolower((string) $info['mime']), ALLOWED_IMAGE_TYPES)) {
        throw new RuntimeException('Yalnızca JPG, PNG, GIF ve WEBP formatları desteklenir.');
    }
}


/* =====================================================================
 *  2) TEK KAYIT GETİRME
 * ================================================================== */
function handle_fetch(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false || $id === null) {
        json_error('Geçersiz kayıt numarası.');
    }

    $user = find_user($db, $id);

    if ($user === null) {
        json_error('Kayıt bulunamadı.', 404);
    }

    /* Formu doldurmak için HAM değer, detay modalını doldurmak için
     * BİÇİMLENDİRİLMİŞ değer gerekir. İkisini de gönderiyoruz:
     *   maas          → "92500.00"   (form alanına yazılır)
     *   maas_format   → "92.500,00"  (ekranda gösterilir) */
    json_response([
        'success'         => true,
        'id'              => (int) $user['id'],
        'name'            => $user['name'],
        'surname'         => $user['surname'],
        'email'           => $user['email'],
        'departman'       => $user['departman'],
        'maas'            => $user['maas'],
        'maas_format'     => format_money($user['maas']),
        'baslama_tarihi'  => $user['baslama_tarihi'],
        'baslama_format'  => format_day($user['baslama_tarihi']),
        'image'           => $user['image'],
        'image_url'       => $user['image'] !== '' ? UPLOAD_URL . rawurlencode((string) $user['image']) : '',
        'tarih'           => format_date($user['tarih']),
    ]);
}


/* =====================================================================
 *  3) KAYIT SİLME
 * ================================================================== */
function handle_delete(PDO $db): void
{
    require_csrf();

    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($id === false || $id === null) {
        json_error('Geçersiz kayıt numarası.');
    }

    $image = get_user_image($db, $id);

    $stmt = $db->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        json_error('Silinecek kayıt bulunamadı.', 404);
    }

    if ($image !== '') {
        delete_upload($image);
    }

    json_success('Kayıt başarıyla silindi.', ['id' => $id]);
}


/* =====================================================================
 *  4) LİSTELEME (DataTables Server-Side Protokolü)
 * ================================================================== */
function handle_list(PDO $db): void
{
    /* LİSTELEME DE CSRF İSTER.
     *
     * ÖLÇÜLEN BOŞLUK: Bu satır eklenmeden önce action=list, anahtar
     * hiç gönderilmeden 200 dönüyor ve TÜM kayıtları veriyordu —
     * oysa README "dışa aktarma dahil her istek doğrulanır" diyordu.
     * Belge ile kod ayrışmıştı.
     *
     * "Tarayıcı zaten yanıtı okuyamaz (CORS)" doğrudur; bu yüzden
     * doğrudan bir veri sızıntısı değildi. Ama bir uç noktanın
     * korumasız olması, ileride oraya bir filtre/parametre eklendiğinde
     * sessizce gerçek bir açığa dönüşür. Kural tek olmalı: durum
     * değiştirsin değiştirmesin, veri döndüren her uç nokta doğrulanır.
     *
     * İstemci tarafında ek iş GEREKMEDİ: index.php'deki DataTables
     * yapılandırması csrf_token'ı zaten her istekte gönderiyordu. */
    require_csrf();

    /* SIRALAMA GÜVENLİĞİ: Sütun adı prepared statement ile bind
     * EDİLEMEZ. Kullanıcıdan gelen sayıyı beyaz listeden geçirip
     * sütun adını BİZ seçiyoruz.
     *
     * Anahtarlar index.php'deki tablo sütun sırasıyla eşleşir:
     *   0=#  1=Foto  2=Ad  3=Soyad  4=E-posta  5=Departman
     *   6=Maaş  7=Başlama  8=İşlemler
     * 1 ve 8 listede yok; veritabanı sütunu değiller. */
    $sortableColumns = [
        0 => 'id',
        2 => 'name',
        3 => 'surname',
        4 => 'email',
        5 => 'departman',
        6 => 'maas',
        7 => 'baslama_tarihi',
    ];

    $draw   = (int) ($_POST['draw'] ?? 1);
    $start  = max(0, (int) ($_POST['start'] ?? 0));
    $length = (int) ($_POST['length'] ?? 10);
    $search = trim((string) ($_POST['search']['value'] ?? ''));

    $orderColumn    = (int) ($_POST['order'][0]['column'] ?? 0);
    $orderDirection = strtolower((string) ($_POST['order'][0]['dir'] ?? 'desc'));

    $orderBy  = $sortableColumns[$orderColumn] ?? 'id';
    $orderDir = ($orderDirection === 'asc') ? 'ASC' : 'DESC';

    $sql    = 'SELECT id, name, surname, email, departman, maas, baslama_tarihi, image FROM users';
    $params = [];

    if ($search !== '') {
        $sql   .= ' WHERE ' . user_search_condition();
        $params = user_search_params($search);
    }

    $sql .= sprintf(' ORDER BY `%s` %s', $orderBy, $orderDir);

    if ($length > 0) {
        $sql .= sprintf(' LIMIT %d OFFSET %d', min($length, 500), $start);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $data = [];

    foreach ($rows as $row) {
        $id       = (int) $row['id'];
        $image    = (string) $row['image'];
        $fullName = $row['name'] . ' ' . $row['surname'];

        if ($image !== '') {
            $imageHtml = '<img src="' . e(UPLOAD_URL . rawurlencode($image)) . '"'
                       . ' class="cy-avatar" alt="' . e($fullName) . '" loading="lazy">';
        } else {
            $initial = mb_strtoupper(mb_substr((string) $row['name'], 0, 1, 'UTF-8'), 'UTF-8');
            $imageHtml = '<span class="cy-avatar cy-avatar--initial" title="Görsel yok">'
                       . e($initial) . '</span>';
        }

        $actionsHtml =
            '<div class="cy-actions">'
            . '<button type="button" class="cy-btn-icon cy-btn-icon--view js-view"'
                . ' data-id="' . $id . '" title="Detayı Gör" aria-label="Detayı gör">&#128065;</button>'
            . '<button type="button" class="cy-btn-icon cy-btn-icon--edit js-edit"'
                . ' data-id="' . $id . '" title="Düzenle" aria-label="Düzenle">&#9998;</button>'
            . '<button type="button" class="cy-btn-icon cy-btn-icon--delete js-delete"'
                . ' data-id="' . $id . '" data-label="' . e($fullName) . '"'
                . ' title="Sil" aria-label="Sil">&#128465;</button>'
            . '</div>';

        // Sütun sırası: #, Foto, Ad, Soyad, E-posta, Departman, Maaş, Başlama, İşlemler
        $data[] = [
            $id,
            $imageHtml,
            e((string) $row['name']),
            e((string) $row['surname']),
            '<span class="cy-nowrap">' . e((string) $row['email']) . '</span>',
            e((string) $row['departman']),
            '<span class="cy-nowrap">' . e(format_money($row['maas'])) . '</span>',
            '<span class="cy-nowrap">' . e(format_day($row['baslama_tarihi'])) . '</span>',
            $actionsHtml,
        ];
    }

    json_response([
        'draw'            => $draw,
        'recordsTotal'    => count_users($db),
        'recordsFiltered' => count_users($db, $search),
        'data'            => $data,
    ]);
}


/* =====================================================================
 *  5) İÇE AKTARMA – 1. ADIM: ÖNİZLEME
 * =====================================================================
 *  Bu adım veritabanına HİÇBİR ŞEY YAZMAZ. Yaptıkları:
 *    a) Yüklenen dosyayı güvenlik açısından doğrular
 *    b) Excel'i okur ve başlıkları alan adlarıyla eşleştirir
 *    c) Her satırı tek tek doğrular
 *    d) Hangi satırın EKLENECEĞİNİ, hangisinin GÜNCELLENECEĞİNİ bulur
 *    e) Geçerli satırları oturuma saklar, ekrana önizleme döndürür
 * ------------------------------------------------------------------ */
function handle_import_preview(PDO $db): void
{
    require_csrf();

    $path = validate_import_upload($_FILES['import_file'] ?? null);

    /* --- SATIR SINIRI VE "SESSİZ KIRPMA" SORUNU -------------------
     *
     * ÖLÇÜLEN HATA: Burada eskiden IMPORT_MAX_ROWS + 1 satır
     * okunuyordu. 10.000 satırlık bir dosya yüklediğimizde önizleme
     * hiçbir uyarı vermeden "toplam 2000 satır" diyordu; kullanıcı
     * onayladığında geri kalan 8.000 satır SESSİZCE KAYBOLUYORDU.
     * Kullanıcının bunu fark etmesinin hiçbir yolu yoktu — üstelik
     * "içe aktarma tamamlandı" yazan yeşil bir mesaj görüyordu.
     *
     * Sessiz veri kaybı, açık bir hatadan çok daha kötüdür: hata
     * görürseniz dosyayı bölersiniz, kaybı ise aylar sonra fark
     * edersiniz.
     *
     * ÇÖZÜM – "TAŞMA DEDEKTÖRÜ": Sınırın 1 fazlasını okumaya
     * çalışıyoruz (başlık + izin verilen + 1). Okuyucu bu kadar satır
     * döndürebildiyse dosyada sınırdan FAZLA veri var demektir; kırpıp
     * devam etmek yerine dosyayı REDDEDİP durumu açıkça söylüyoruz.
     *
     * Okuyucuyu değiştirmeye gerek kalmadı: XlsxReader zaten maxRows
     * kadar okuyup duruyor, biz yalnızca ne istediğimizi bir artırdık.
     * Fazladan maliyet tek bir satırdır. */
    $probeLimit = IMPORT_MAX_ROWS + 2;
    $rows       = XlsxReader::read($path, $probeLimit);

    if (count($rows) >= $probeLimit) {
        json_error(sprintf(
            'Dosyada en fazla %s veri satırı olabilir; yüklediğiniz dosyada daha fazlası var. '
            . 'Hiçbir satır işlenmedi. Lütfen dosyayı parçalara bölüp tekrar yükleyin.',
            number_format(IMPORT_MAX_ROWS, 0, ',', '.')
        ), 422);
    }

    if (count($rows) < 2) {
        json_error('Dosyada başlık satırının altında hiç veri bulunamadı.', 422);
    }

    /* --- BAŞLIKLARI EŞLEŞTİR --------------------------------------
     * Kullanıcının sütun SIRASINA güvenmiyoruz; başlık METNİNE
     * bakıyoruz. Böylece sütunların yeri değişse, araya yeni sütun
     * girse veya fazladan sütunlar bulunsa bile dosya okunabilir. */
    $headerRow = array_shift($rows);
    $aliases   = excel_header_aliases();
    $map       = []; // sütun numarası => alan adı

    foreach ($headerRow as $index => $label) {
        $key = normalize_header((string) $label);

        // Tanımadığımız sütunlar (örn. "#", "Kayıt Tarihi") yok sayılır.
        if ($key !== '' && isset($aliases[$key])) {
            $map[$index] = $aliases[$key];
        }
    }

    /* --- ZORUNLU SÜTUNLAR VAR MI? --------------------------------- */
    $missing = [];

    foreach (excel_columns() as $field => $column) {
        if ($column['required'] && !in_array($field, $map, true)) {
            $missing[] = $column['label'];
        }
    }

    if ($missing !== []) {
        json_error(
            'Dosyada şu zorunlu sütunlar bulunamadı: ' . implode(', ', $missing)
            . '. Doğru başlıklar için "Şablon İndir" butonunu kullanabilirsiniz.',
            422
        );
    }

    /* --- SATIRLARI DOĞRULA ---------------------------------------- */
    $preview    = [];  // Ekranda gösterilecek satırlar
    $validRows  = [];  // Onaylanırsa yazılacak temiz veriler
    $seenEmails = [];  // Dosya İÇİNDEKİ mükerrerleri yakalamak için

    foreach ($rows as $index => $row) {
        // +2 : Başlık satırını çıkardık (+1) ve Excel 1'den başlar (+1).
        // Böylece kullanıcıya söylediğimiz satır numarası, Excel'de
        // gördüğü satır numarasıyla birebir aynı olur.
        $excelRowNumber = $index + 2;

        [$values, $errors] = validate_import_row($row, $map);

        /* Dosyanın kendi içinde aynı e-posta iki kez geçiyorsa,
         * ikinci satır hangi değeri kazanacağı belirsiz olduğu için
         * reddedilir. (Sessizce üzerine yazmak, kullanıcının
         * fark etmediği veri kaybına yol açar.) */
        if ($errors === [] && isset($seenEmails[$values['email']])) {
            $errors['email'] = 'Bu e-posta dosyanın ' . $seenEmails[$values['email']]
                             . '. satırında zaten var.';
        }

        if ($errors === []) {
            $seenEmails[$values['email']] = $excelRowNumber;
        }

        $preview[] = [
            'row'    => $excelRowNumber,
            'values' => $values,
            'errors' => $errors,
            // Durum, aşağıda veritabanı kontrolünden sonra netleşir.
            'status' => $errors === [] ? 'insert' : 'error',
        ];

        if ($errors === []) {
            $validRows[] = $values;
        }
    }

    /* --- EKLEME Mİ, GÜNCELLEME Mİ, YOKSA DEĞİŞİKLİK YOK MU? --------
     * Tek tek 500 sorgu atmak yerine tüm e-postaları TEK sorguda
     * soruyoruz. Bu, "N+1 sorgu problemi" denen ve içe aktarmayı
     * dakikalarca sürdüren klasik hatanın çözümüdür.
     *
     * Aynı dosyanın iki kez yüklenmesi ya da satırların hiç
     * değişmemiş olması sık karşılaşılan bir durumdur. Böyle bir
     * satırı "güncellenecek" diye göstermek YANILTICIDIR; kullanıcı
     * hiçbir şey değişmediği hâlde "5 kayıt güncellendi" görür.
     * user_row_changed() bu iki durumu ayırır. */
    $existing = find_existing_users($db, array_column($validRows, 'email'));

    // Kaydetme adımında yalnızca GERÇEKTEN yazılacak satırlar
    // gönderilir; "unchanged" satırlar oturuma dahi taşınmaz.
    $rowsToSave = [];

    foreach ($preview as &$item) {
        if ($item['status'] !== 'insert') {
            continue;
        }

        $email = $item['values']['email'];

        if (isset($existing[$email])) {
            $item['status'] = user_row_changed($existing[$email], $item['values'])
                ? 'update'
                : 'unchanged';
        }

        if ($item['status'] !== 'unchanged') {
            $rowsToSave[] = $item['values'];
        }
    }
    unset($item); // Referansla döngü sonrası $item'ı serbest bırak.

    /* --- KAYDEDİLECEK SATIRLARI OTURUMA SAKLA ----------------------
     * Onay adımında dosyayı tekrar yüklemek gerekmesin diye
     * doğrulanmış veriyi sunucuda tutuyoruz. "unchanged" satırlar
     * zaten yukarıda elenmiş durumda; kaydetme adımı hiçbir zaman
     * gereksiz bir UPDATE çalıştırmaz.
     *
     * NEDEN İSTEMCİYE GÖNDERİP GERİ ALMIYORUZ?
     * Çünkü o veri kullanıcı tarafından değiştirilebilir. Doğrulamayı
     * atlatmak isteyen biri, önizlemeden sonra JSON'u düzenleyip
     * geçersiz veri gönderebilirdi. Sunucuda tutulan veri güvenlidir.
     *
     * Rastgele "token", eski bir önizlemenin yanlışlıkla
     * onaylanmasını engeller. */
    $token = bin2hex(random_bytes(16));

    $_SESSION['import_batch'] = [
        'token'   => $token,
        'rows'    => $rowsToSave,
        'created' => time(),
    ];

    $invalidCount = count(array_filter($preview, static fn ($r) => $r['status'] === 'error'));

    json_response([
        'success'  => true,
        'token'    => $token,
        'columns'  => array_map(
            static fn (array $column): string => $column['label'],
            excel_columns()
        ),
        'fields'   => array_keys(excel_columns()),
        'rows'     => $preview,
        'summary'  => [
            'total'     => count($preview),
            'valid'     => count($rowsToSave),
            'invalid'   => $invalidCount,
            'insert'    => count(array_filter($preview, static fn ($r) => $r['status'] === 'insert')),
            'update'    => count(array_filter($preview, static fn ($r) => $r['status'] === 'update')),
            'unchanged' => count(array_filter($preview, static fn ($r) => $r['status'] === 'unchanged')),
        ],
    ]);
}


/**
 * Yüklenen dosyayı güvenlik açısından doğrular.
 *
 * @param array<string,mixed>|null $file $_FILES['import_file']
 * @return string Dosyanın geçici yolu
 */
function validate_import_upload(?array $file): string
{
    if ($file === null || !isset($file['error']) || is_array($file['error'])) {
        json_error('Geçersiz dosya yükleme isteği.');
    }

    switch ((int) $file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            json_error('Dosya boyutu sunucu limitini aşıyor.', 422);
            // no break – json_error zaten exit eder
        case UPLOAD_ERR_NO_FILE:
            json_error('Lütfen bir Excel dosyası seçin.', 422);
        default:
            json_error('Dosya yüklenirken bir hata oluştu.', 500);
    }

    if ((int) $file['size'] <= 0 || (int) $file['size'] > IMPORT_MAX_BYTES) {
        json_error(
            'Dosya boyutu en fazla ' . (int) (IMPORT_MAX_BYTES / 1024 / 1024) . ' MB olabilir.',
            422
        );
    }

    /* is_uploaded_file(): Dosyanın gerçekten HTTP yüklemesiyle
     * geldiğini doğrular. Bu kontrol olmazsa saldırgan tmp_name
     * alanına sunucudaki başka bir dosyanın yolunu yazıp onu
     * okutabilirdi. */
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        json_error('Geçersiz dosya kaynağı.');
    }

    /* MIME kontrolü sadece bir ÖN ELEMEDİR; kolayca taklit edilebilir.
     * Asıl doğrulama XlsxReader içinde yapılır: dosya gerçekten
     * geçerli bir ZIP paketi mi ve içinde xl/workbook.xml var mı? */
    $mime = strtolower((string) ($file['type'] ?? ''));

    if ($mime !== '' && !in_array($mime, ALLOWED_IMPORT_TYPES, true)) {
        json_error('Yalnızca .xlsx uzantılı Excel dosyaları yüklenebilir.', 422);
    }

    return (string) $file['tmp_name'];
}


/**
 * Excel'den gelen bir satırı doğrular ve temiz veriye çevirir.
 *
 * @param array<int,string>  $row Satırın hücre değerleri
 * @param array<int,string>  $map Sütun numarası => alan adı
 * @return array{0:array<string,mixed>,1:array<string,string>} [değerler, hatalar]
 */
function validate_import_row(array $row, array $map): array
{
    /* Önce ham değerleri alan adlarına dağıt.
     * Eşleşmeyen sütunlar (dosyadaki "#" gibi) buraya hiç girmez. */
    $raw = [];

    foreach (excel_columns() as $field => $column) {
        $raw[$field] = '';
    }

    foreach ($map as $index => $field) {
        $raw[$field] = isset($row[$index]) ? trim((string) $row[$index]) : '';
    }

    $values = [];
    $errors = [];

    [$values['name'], $nameError]           = validate_name($raw['name'], 'Ad');
    [$values['surname'], $surnameError]     = validate_name($raw['surname'], 'Soyad');
    [$values['email'], $emailError]         = validate_email($raw['email']);
    [$values['departman'], $departmanError] = validate_departman($raw['departman']);
    [$values['maas'], $maasError]           = validate_maas($raw['maas']);
    [$values['baslama_tarihi'], $tarihError] = validate_tarih($raw['baslama_tarihi']);

    foreach ([
        'name'           => $nameError,
        'surname'        => $surnameError,
        'email'          => $emailError,
        'departman'      => $departmanError,
        'maas'           => $maasError,
        'baslama_tarihi' => $tarihError,
    ] as $field => $message) {
        if ($message !== null) {
            $errors[$field] = $message;

            /* Hatalı alanın HAM hâlini geri koyuyoruz. Kullanıcı
             * önizlemede "Maaş: (boş)" değil, kendi yazdığı
             * "elli bin" değerini görsün ki neyi düzelteceğini
             * anlasın. */
            $values[$field] = $raw[$field];
        }
    }

    return [$values, $errors];
}


/**
 * Verilen e-postalara sahip kayıtları, KARŞILAŞTIRMA YAPABİLECEK
 * kadar tam olarak veritabanından getirir.
 *
 * NEDEN SADECE id DEĞİL, TÜM ALANLAR?
 * Bir satırın "ekleme mi, güncelleme mi, yoksa hiç değişmemiş mi"
 * olduğuna karar vermek için mevcut değerlerle karşılaştırma
 * yapmamız gerekir (bkz. user_row_changed()). Yalnızca id dönseydi,
 * bu karşılaştırmayı yapmak için satır başına ayrı bir sorgu
 * gerekirdi — N+1 sorgu problemine geri dönerdik.
 *
 * @param  string[] $emails
 * @return array<string,array<string,mixed>> eposta => satır
 */
function find_existing_users(PDO $db, array $emails): array
{
    $emails = array_values(array_unique(array_filter($emails)));

    if ($emails === []) {
        return [];
    }

    $found = [];

    /* PARÇALARA BÖLME (chunk):
     * Tek bir IN(...) listesine binlerce değer koymak, MySQL'in
     * max_allowed_packet sınırını aşabilir ve sorguyu patlatır.
     * 500'erlik gruplar hem güvenli hem yeterince hızlıdır. */
    foreach (array_chunk($emails, 500) as $chunk) {
        // Her değer için bir "?" yer tutucu üret: "?,?,?"
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));

        $stmt = $db->prepare(
            'SELECT id, name, surname, email, departman, maas, baslama_tarihi
               FROM users WHERE email IN (' . $placeholders . ')'
        );
        $stmt->execute($chunk);

        foreach ($stmt->fetchAll() as $row) {
            $found[(string) $row['email']] = $row;
        }
    }

    return $found;
}


/* =====================================================================
 *  6) İÇE AKTARMA – 2. ADIM: KAYDET
 * =====================================================================
 *  Önizlemede saklanan geçerli satırları veritabanına yazar.
 *  Tamamı TEK BİR TRANSACTION içindedir: 900. satırda hata çıkarsa
 *  ilk 899 satır da geri alınır. Yarısı işlenmiş bir içe aktarma,
 *  hiç işlenmemiş olandan çok daha kötüdür; hangi satıra kadar
 *  gittiğini bilmeden dosyayı tekrar yükleyemezsiniz.
 * ------------------------------------------------------------------ */
function handle_import_commit(PDO $db): void
{
    require_csrf();

    $token = (string) ($_POST['token'] ?? '');
    $batch = $_SESSION['import_batch'] ?? null;

    if (!is_array($batch) || !isset($batch['token'], $batch['rows'])) {
        json_error('Önizleme bulunamadı. Lütfen dosyayı tekrar yükleyin.', 422);
    }

    // hash_equals(): Zamanlama saldırısına karşı sabit süreli karşılaştırma.
    if ($token === '' || !hash_equals((string) $batch['token'], $token)) {
        json_error('Önizleme anahtarı geçersiz. Lütfen dosyayı tekrar yükleyin.', 422);
    }

    $rows = $batch['rows'];

    if ($rows === []) {
        json_error('Kaydedilecek geçerli satır yok.', 422);
    }

    /* --- EKLE Mİ, GÜNCELLE Mİ, YOKSA ATLA MI? ----------------------
     * Bu kontrolü ÖNİZLEMEDEKİ sonuca güvenip atlayamayız: kullanıcı
     * önizlemeye bakarken başka biri aynı e-postayı eklemiş veya
     * AYNI DEĞİŞİKLİĞİ zaten yapmış olabilir. Bu yüzden transaction'ın
     * içinde yeniden soruyoruz ve yeniden karşılaştırıyoruz.
     *
     * "Atla" durumu önizlemede zaten elenmiş satırlar için beklenmez
     * ama savunmacı bir kontrol: gereksiz bir UPDATE çalıştırmak,
     * hiçbir alanı değiştirmese bile updated_at benzeri alanları
     * (ileride eklenirse) anlamsız yere güncelleyebilir ve MySQL'e
     * boş yere yazma yükü bindirir. */
    $db->beginTransaction();

    try {
        $existing = find_existing_users($db, array_column($rows, 'email'));

        $insert = $db->prepare(
            'INSERT INTO users (name, surname, email, departman, maas, baslama_tarihi)
             VALUES (:name, :surname, :email, :departman, :maas, :baslama)'
        );

        /* Güncellemede "image" ve "tarih" sütunlarına DOKUNULMAZ.
         * Excel'de bu bilgiler yoktur; kullanıcının yüklediği profil
         * görselini bir içe aktarma yüzünden kaybetmesi kabul edilemez. */
        $update = $db->prepare(
            'UPDATE users SET name = :name, surname = :surname, departman = :departman,
                    maas = :maas, baslama_tarihi = :baslama
              WHERE email = :email'
        );

        $inserted = 0;
        $updated  = 0;
        $skipped  = 0;

        foreach ($rows as $row) {
            $current = $existing[$row['email']] ?? null;

            if ($current === null) {
                $insert->execute([
                    ':name'      => $row['name'],
                    ':surname'   => $row['surname'],
                    ':email'     => $row['email'],
                    ':departman' => $row['departman'],
                    ':maas'      => $row['maas'],
                    ':baslama'   => $row['baslama_tarihi'],
                ]);
                $inserted++;
                continue;
            }

            if (!user_row_changed($current, $row)) {
                // Değer zaten aynı: yazmaya gerek yok.
                $skipped++;
                continue;
            }

            $update->execute([
                ':name'      => $row['name'],
                ':surname'   => $row['surname'],
                ':departman' => $row['departman'],
                ':maas'      => $row['maas'],
                ':baslama'   => $row['baslama_tarihi'],
                ':email'     => $row['email'],
            ]);
            $updated++;
        }

        $db->commit();
    } catch (Throwable $e) {
        // Hata hâlinde YARIM İŞ BIRAKMA: her şeyi geri al.
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e; // Üstteki genel catch bloğu JSON hatasına çevirir.
    }

    // Parti tamamlandı; oturumdaki veriyi temizle ki aynı önizleme
    // ikinci kez onaylanamasın.
    unset($_SESSION['import_batch']);

    $message = sprintf('İçe aktarma tamamlandı: %d yeni kayıt eklendi, %d kayıt güncellendi.', $inserted, $updated);

    if ($skipped > 0) {
        $message .= sprintf(' (%d kayıt zaten günceldi, atlandı.)', $skipped);
    }

    json_success($message, ['inserted' => $inserted, 'updated' => $updated, 'skipped' => $skipped]);
}
