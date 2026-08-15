<?php
/**
 * =====================================================================
 *  DIŞA AKTARMA UÇ NOKTASI – Excel (.xlsx) indirir
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  Bu dosya JSON DEĞİL, DOSYA döndürür. İki görevi vardır:
 *
 *    export=data     → Veritabanındaki kayıtları Excel'e döker
 *    export=template → Boş şablon indirir (içe aktarma için)
 *
 *  NEDEN AJAX DEĞİL DE FORM GÖNDERİMİ?
 *  Tarayıcı, AJAX ile gelen yanıtı "indirilen dosya" olarak kaydedemez;
 *  veriyi JavaScript'in eline verir. Dosya indirmenin doğru yolu,
 *  tarayıcının kendisinin bir istek yapmasıdır. Bu yüzden index.php
 *  içindeki JavaScript, görünmez bir <form> oluşturup buraya POST eder.
 *
 *  NEDEN GET DEĞİL DE POST?
 *  Böylece CSRF anahtarını isteğe ekleyebiliyoruz. Dışa aktarma
 *  veriyi DEĞİŞTİRMEZ ama TÜM KAYITLARI dışarı verir; başka bir
 *  sitenin gizlice tetikleyebileceği bir uç nokta olmamalıdır.
 * =====================================================================
 */

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/function.php';
require __DIR__ . '/Excel/XlsxWriter.php';

/* ---------------------------------------------------------------------
 *  GÜVENLİK
 * ------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Yalnızca POST istekleri kabul edilir.');
}

/* DİKKAT – require_csrf() burada JSON hata döndürür.
 * Dosya indirme ekranında JSON görmek tuhaf olsa da, bu durum
 * yalnızca oturum düştüğünde veya sahte istekte oluşur; kullanıcı
 * sayfayı yenilediğinde sorun çözülür. Önemli olan, anahtar
 * doğrulanmadan hiçbir verinin dışarı çıkmamasıdır. */
require_csrf();

try {
    $type = ($_POST['export'] ?? 'data') === 'template' ? 'template' : 'data';

    if ($type === 'template') {
        export_template();
    }

    export_data($db);
} catch (Throwable $e) {
    error_log('[EXCEL] Disa aktarma hatasi: ' . $e->getMessage());

    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    echo APP_DEBUG
        ? 'Excel oluşturulamadı: ' . $e->getMessage()
        : 'Excel dosyası oluşturulurken bir hata oluştu.';

    exit;
}


/* =====================================================================
 *  1) KAYITLARI DIŞA AKTAR
 * ================================================================== */
function export_data(PDO $db): void
{
    // Ekranda arama yapılmışsa yalnızca eşleşen kayıtlar aktarılır.
    $search  = trim((string) ($_POST['search'] ?? ''));
    $rows    = fetch_users_for_export($db, $search);
    $columns = excel_columns();

    $writer = new XlsxWriter('Kullanıcılar');

    /* --- BAŞLIK SATIRI --------------------------------------------
     * Sütun listesi excel_columns()'tan gelir; başına "#" (kayıt no),
     * sonuna "Kayıt Tarihi" eklenir. Bu iki sütun içe aktarmada
     * YOK SAYILIR, sadece bilgi amaçlıdır. Böylece kullanıcı dosyayı
     * indirip düzenleyip geri yükleyebilir; fazladan sütunlar
     * içe aktarmayı bozmaz. */
    $labels = ['#'];
    $widths = [8];
    $types  = ['int'];

    foreach ($columns as $column) {
        $labels[] = $column['label'];
        $widths[] = $column['width'];
        $types[]  = $column['type'];
    }

    $labels[] = 'Kayıt Tarihi';
    $widths[] = 18;
    $types[]  = 'text';

    $writer->setHeader($labels, $widths);

    /* --- VERİ SATIRLARI ------------------------------------------- */
    foreach ($rows as $row) {
        $values = [(int) $row['id']];

        foreach ($columns as $field => $column) {
            // Boş değerler null gönderilir; XlsxWriter bunu Excel'de
            // GERÇEKTEN boş hücre yapar (içi "" olan hücre değil).
            $values[] = ($row[$field] === null || $row[$field] === '') ? null : $row[$field];
        }

        $values[] = format_date($row['tarih']);

        $writer->addRow($values, $types);
    }

    // Dosya adına tarih-saat koymak, arka arkaya alınan çıktıların
    // birbirinin üzerine yazılmasını önler.
    $writer->download(EXPORT_FILENAME_PREFIX . '-' . date('Ymd-Hi') . '.xlsx');
}


/* =====================================================================
 *  2) BOŞ ŞABLON İNDİR
 * =====================================================================
 *  İÇE AKTARMANIN EN ÖNEMLİ PARÇASI BUDUR.
 *  Kullanıcıya "şu sütunları şu sırayla hazırlayın" demek yerine
 *  doğru başlıklara sahip hazır bir dosya vermek, hatalı yüklemelerin
 *  büyük kısmını daha başlamadan ortadan kaldırır.
 * ================================================================== */
function export_template(): void
{
    $columns = excel_columns();

    $labels = [];
    $widths = [];
    $types  = [];

    foreach ($columns as $column) {
        $labels[] = $column['label'];
        $widths[] = $column['width'];
        $types[]  = $column['type'];
    }

    $writer = new XlsxWriter('Şablon');
    $writer->setHeader($labels, $widths);

    /* İki örnek satır: biri tüm alanları dolu, diğeri sadece zorunlu
     * alanlar. Kullanıcı böylece neyin boş bırakılabileceğini
     * açıklama okumadan görür. */
    $writer->addRow(
        ['Evren', 'ÇILGIN', 'ornek1@ornek.com', 'Yazılım', 92500.00, '2019-03-04'],
        $types
    );

    $writer->addRow(
        ['Zeynep', 'TURAN', 'ornek2@ornek.com', null, null, null],
        $types
    );

    $writer->download('ice-aktarma-sablonu.xlsx');
}
