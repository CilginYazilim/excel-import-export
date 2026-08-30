/* =====================================================================
 *  UYGULAMA DAVRANIŞI
 *  cilginyazilim.com – PHP Excel Dışa/İçe Aktarma
 * ---------------------------------------------------------------------
 *  BU DOSYA NEDEN AYRI?
 *  Eskiden bu kodun tamamı index.php'nin içinde <script> etiketiyle
 *  duruyordu ve tek başına 690 satırdı — dosyanın yarısından fazlası.
 *  Ayırmanın üç somut faydası var:
 *
 *    1. ÖNBELLEK: index.php her istekte yeniden üretilir ve
 *       önbelleğe alınamaz. Bu dosya statiktir; tarayıcı bir kez
 *       indirip saklar. Sayfa gövdesi ~55 KB'tan ~20 KB'a iner.
 *    2. OKUNABİLİRLİK: index.php artık "sayfa neye benziyor",
 *       bu dosya "sayfa ne yapıyor" sorusunu yanıtlar. İkisi
 *       birbirine karışmaz.
 *    3. ARAÇ DESTEĞİ: Editörler ve linter'lar .js dosyasını
 *       anlar; PHP içine gömülü JavaScript'i anlamaz.
 *
 *  PHP DEĞERLERİNİ NASIL ALIYOR?
 *  Bu dosya statik olduğu için içine PHP yazamayız. Sunucudan gelmesi
 *  gereken değerler (CSRF anahtarı, satır/boyut sınırları, sütun
 *  listesi) index.php içinde bir <script type="application/json">
 *  bloğuna basılır; buradaki readConfig() onu okur.
 *
 *  NEDEN JSON BLOĞU, "var x = <?= ... ?>" DEĞİL?
 *  Çünkü JSON bloğunun içeriği tarayıcı tarafından KOD olarak
 *  çalıştırılmaz, sadece metin olarak okunur. Değişkene doğrudan
 *  PHP basmak, veride tek bir </script> veya tırnak kaçışı hatası
 *  olduğunda sayfaya kod enjekte edilmesine yol açabilir.
 * ================================================================== */
$(function () {
    'use strict';

    /* -----------------------------------------------------------------
     *  YAPILANDIRMA
     * -------------------------------------------------------------- */
    function readConfig() {
        var node = document.getElementById('cy-config');
        return node ? JSON.parse(node.textContent) : {};
    }

    var CONFIG     = readConfig();
    var ENDPOINT   = CONFIG.ajaxUrl;      // JSON döndüren uç nokta
    var EXPORT_URL = CONFIG.exportUrl;    // Dosya indiren uç nokta
    var CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');
    var MAX_BYTES  = CONFIG.imageMaxBytes;
    var IMPORT_MAX = CONFIG.importMaxBytes;

    // Önizleme tablosunun sütun sırası. PHP'deki excel_columns()
    // tanımından üretilir; iki taraf böylece asla ayrışmaz.
    var IMPORT_FIELDS = CONFIG.importFields || [];

    var userModal   = new bootstrap.Modal(document.getElementById('userModal'));
    var detailModal = new bootstrap.Modal(document.getElementById('detailModal'));
    var deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    var importModal = new bootstrap.Modal(document.getElementById('importModal'));

    var pendingDeleteId = null;
    var currentDetailId = null;

    // Önizleme adımından dönen anahtar. Kaydet butonu bunu sunucuya
    // geri gönderir; sunucu hangi partiyi yazacağını bundan bilir.
    var importToken = null;


    /* =============================================================
     *  YARDIMCI FONKSİYONLAR
     * ============================================================= */

    /** Sağ üstte geçici bildirim (toast) gösterir. */
    function notify(message, type) {
        type = type || 'success';

        var $toast = $(
            '<div class="toast cy-toast cy-toast--' + type + '" role="alert" aria-live="assertive" aria-atomic="true">' +
                '<div class="d-flex">' +
                    '<div class="toast-body"></div>' +
                    '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Kapat"></button>' +
                '</div>' +
            '</div>'
        );

        // .html() değil .text(): mesaj içindeki HTML çalışmasın (XSS).
        $toast.find('.toast-body').text(message);
        $('#toast_container').append($toast);

        var toast = new bootstrap.Toast($toast[0], { delay: 5000 });
        $toast.on('hidden.bs.toast', function () { $toast.remove(); });
        toast.show();
    }

    /**
     * Sunucudan gelen hatayı tek bir yerden yorumlar.
     *
     * NEDEN AYRI FONKSİYON?
     * Oturum düşmesi (403) her uç noktada olabilir ve kullanıcının
     * yapması gereken şey her seferinde AYNIDIR: sayfayı yenilemek.
     * Bunu her .fail() bloğunda ayrı ayrı yazmak, birini güncelleyip
     * diğerini unutmak demektir.
     */
    function errorMessage(xhr, fallback) {
        var res = xhr.responseJSON || {};

        if (xhr.status === 403) {
            return res.description || 'Oturumunuz sona ermiş. Lütfen sayfayı yenileyin.';
        }

        return res.description || fallback;
    }

    function clearErrors() {
        var $form = $('#user_form');
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('[data-error-for]').text('');
        $('#form_alert').addClass('d-none').text('');
    }

    function showErrors(errors, general) {
        clearErrors();

        if (general) {
            $('#form_alert').removeClass('d-none').text(general);
        }

        $.each(errors || {}, function (field, message) {
            $('#' + field).addClass('is-invalid');
            $('[data-error-for="' + field + '"]').text(message);
        });
    }

    function setLoading(isLoading) {
        $('#submit_button').prop('disabled', isLoading);
        $('#submit_spinner').toggleClass('d-none', !isLoading);
        $('#submit_label').text(isLoading ? 'Kaydediliyor…' : 'Kaydet');
    }

    function resetForm() {
        $('#user_form')[0].reset();
        $('#user_id').val('');
        $('#image_preview_wrapper').addClass('d-none');
        $('#image_preview').attr('src', '');
        clearErrors();
    }

    function fetchUser(id, onDone) {
        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: { action: 'fetch', id: id, csrf_token: CSRF_TOKEN }
        }).done(onDone).fail(function (xhr) {
            notify(errorMessage(xhr, 'Kayıt getirilemedi.'), 'danger');
        });
    }

    function openEditModal(data) {
        resetForm();
        $('#form_action').val('edit');
        $('#user_id').val(data.id);
        $('#name').val(data.name);
        $('#surname').val(data.surname);
        $('#email').val(data.email);
        $('#departman').val(data.departman);
        $('#maas').val(data.maas);
        $('#baslama_tarihi').val(data.baslama_tarihi || '');
        $('#userModalLabel').text('Kaydı Düzenle');

        if (data.image_url) {
            $('#image_preview').attr('src', data.image_url);
            $('#image_preview_wrapper').removeClass('d-none');
        }
        userModal.show();
    }


    /* =============================================================
     *  DATATABLES KURULUMU
     * ============================================================= */

    /* Sütun başlıkları, mobil kart görünümünde hücre etiketi olarak
     * kullanılır (aşağıdaki rowCallback'e bakın). Tablo başlıkları
     * sayfa ömrü boyunca değişmediği için bir kez okunur. */
    var COLUMN_LABELS = $('#user_data thead th').map(function () {
        return $(this).text().trim();
    }).get();

    var dataTable = $('#user_data').DataTable({

        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100], [10, 25, 50, 100]],

        /* dom SEÇENEĞİ – DataTables'ın ürettiği parçaların yerini
         * belirler. Harfler: l=uzunluk seçici, f=arama kutusu,
         * r=işleniyor göstergesi, t=tablo, i=bilgi metni, p=sayfalama.
         *
         * 'f' BİLİNÇLİ OLARAK YOK: kendi arama kutumuz (#search_input)
         * var, DataTables'ın ikinci bir arama kutusu üretmesine gerek
         * yok — ikisi de aynı işi yapardı ve kafa karıştırırdı.
         *
         * l, i, p TEK BİR ALT ÇUBUKTA toplanır (cy-bottom-bar).
         * "<div class=...>" söz dizimi DataTables'ın kendi şablonlama
         * biçimidir: tırnak içindeki sınıf adıyla bir <div> açar,
         * kapanışını otomatik ekler. Üç parçayı TEK satırda tutmanın
         * en güvenilir yolu budur — sonradan JavaScript ile taşımak
         * (ki önceki sürüm böyleydi) DataTables'ın ürettiği <label>
         * içindeki <select>'in (Bootstrap'te width:100% ve
         * display:block'tur) satırı KIRMASINA yol açıyordu. */
        dom: 'rt<"cy-bottom-bar"<"cy-bottom-bar__length"l><"cy-bottom-bar__info"i><"cy-bottom-bar__pagination"p>>',

        ajax: {
            url: ENDPOINT,
            type: 'POST',
            data: function (d) {
                d.action     = 'list';
                d.csrf_token = CSRF_TOKEN;
            },
            error: function (xhr) {
                // 403 = oturum düştü. Genel "bir hata oluştu" mesajı
                // yerine ne yapması gerektiğini söylüyoruz.
                notify(errorMessage(xhr, 'Kayıtlar yüklenirken bir hata oluştu.'), 'danger');
            }
        },

        columnDefs: [
            { targets: 0, className: 'cy-id' },
            // Foto ve İşlemler sütunları sıralanamaz/aranamaz:
            // içlerinde HTML var, veritabanı sütunu değiller.
            { targets: 1, orderable: false, searchable: false, className: 'text-center' },
            { targets: 2, className: 'cy-name' },
            { targets: 6, className: 'text-end' },
            { targets: 8, orderable: false, searchable: false, className: 'text-center' }
        ],

        /* MOBİL KART GÖRÜNÜMÜ İÇİN ETİKETLER
         * -------------------------------------------------------------
         * Dar ekranda tablo kart düzenine geçer ve <thead> gizlenir
         * (bkz. assets/css/style.css, "TABLO → KART GÖRÜNÜMÜ").
         * Başlıklar gizlenince "ahmet@ornek.com" ile "Yazılım"ın hangi
         * sütuna ait olduğu kaybolur; CSS bunu her hücrenin soluna
         * data-label değerini yazarak geri getirir.
         *
         * NEDEN ETİKETLER CSS'E ELLE YAZILMADI?
         * Yazılsaydı sütun listesi İKİ yerde dururdu: index.php'deki
         * <thead> ve style.css. Biri güncellenip diğeri unutulduğunda
         * mobil kullanıcı YANLIŞ etiketi okurdu — hatanın masaüstünde
         * hiçbir izi olmadığı için de fark edilmezdi. Etiketleri
         * <thead>'den okuyarak tek doğru kaynağı koruyoruz.
         *
         * Hesap her çizimde bir kez yapılır (satır başına değil):
         * başlıklar sayfa ömrü boyunca değişmez. */
        rowCallback: function (row) {
            $(row).children('td').each(function (index) {
                if (COLUMN_LABELS[index]) {
                    this.setAttribute('data-label', COLUMN_LABELS[index]);
                }
            });
        },

        drawCallback: function (settings) {
            $('#total_records').text(settings.json ? settings.json.recordsTotal : 0);
        },

        language: {
            emptyTable:     'Henüz kayıt bulunmuyor.',
            info:           '_TOTAL_ kayıttan _START_ – _END_ arası gösteriliyor',
            infoEmpty:      'Gösterilecek kayıt yok',
            infoFiltered:   '(toplam _MAX_ kayıt içinden filtrelendi)',
            lengthMenu:     'Sayfada _MENU_ kayıt göster',
            loadingRecords: 'Yükleniyor…',
            processing:     'İşleniyor…',
            search:         'Ara:',
            searchPlaceholder: 'Ad, soyad, e-posta, departman',
            zeroRecords:    'Aramanızla eşleşen kayıt bulunamadı.',
            paginate: {
                first: 'İlk', last: 'Son', next: 'Sonraki', previous: 'Önceki'
            },
            aria: {
                sortAscending:  ': artan sırada sıralamak için etkinleştir',
                sortDescending: ': azalan sırada sıralamak için etkinleştir'
            }
        }
    });


    /* =============================================================
     *  ARAMA KUTUSU
     * -------------------------------------------------------------
     *  GECİKTİRME (debounce): Her tuş vuruşunda sunucuya istek atmak,
     *  "yazılım" kelimesi için 7 gereksiz sorgu demektir. Kullanıcı
     *  300 ms yazmayı bıraktığında tek istek atılır.
     * ============================================================= */
    var searchTimer = null;

    $('#search_input').on('input', function () {
        var value = this.value;

        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () {
            dataTable.search(value).draw();
        }, 300);
    });


    /* =============================================================
     *  1) EXCEL'E AKTARMA
     * -------------------------------------------------------------
     *  DOSYA İNDİRME NEDEN AJAX İLE YAPILMAZ?
     *  AJAX yanıtı JavaScript değişkenine düşer; tarayıcı onu
     *  "indirilen dosya" olarak kaydetmez. Doğru yöntem, tarayıcının
     *  kendisinin istek yapmasıdır. Bu yüzden görünmez bir <form>
     *  üretip gönderiyoruz.
     *
     *  target="_blank" KULLANMIYORUZ: sunucu Content-Disposition
     *  başlığı gönderdiği için tarayıcı yeni sekme açmaz, doğrudan
     *  indirir; boş bir sekme açılıp kapanması kullanıcıyı şaşırtır.
     * ============================================================= */
    function postDownload(fields) {
        var $form = $('<form>', { method: 'POST', action: EXPORT_URL });

        fields.csrf_token = CSRF_TOKEN;

        $.each(fields, function (key, value) {
            $('<input>', { type: 'hidden', name: key, value: value }).appendTo($form);
        });

        // Formun gönderilebilmesi için DOM'da olması gerekir.
        $form.appendTo('body').trigger('submit').remove();
    }

    $('#export_button').on('click', function () {
        /* Ekranda arama yapılmışsa aynı filtreyi sunucuya
         * gönderiyoruz; kullanıcı gördüğü listeyi indirsin. */
        var search = dataTable.search();

        postDownload({ export: 'data', search: search });

        notify(
            search
                ? 'Filtrelenmiş liste Excel olarak hazırlanıyor…'
                : 'Tüm kayıtlar Excel olarak hazırlanıyor…',
            'info'
        );
    });

    $('#template_button').on('click', function () {
        postDownload({ export: 'template' });
    });


    /* =============================================================
     *  2) EXCEL'DEN İÇE AKTARMA – ADIM 1: ÖNİZLEME
     * ============================================================= */

    /** Sihirbazı ilk adıma döndürür. */
    function resetImport() {
        importToken = null;

        $('#import_file').val('');
        $('#import_alert').addClass('d-none').text('');
        $('#import_warning').addClass('d-none').text('');
        $('#import_preview_body').empty();
        $('#import_summary').empty();

        $('#import_step_file').removeClass('d-none');
        $('#import_step_preview').addClass('d-none');

        $('#preview_button').removeClass('d-none').prop('disabled', false);
        $('#commit_button').addClass('d-none');
        $('#import_back_button').addClass('d-none');
    }

    $('#import_button').on('click', function () {
        resetImport();
        importModal.show();
    });

    $('#import_back_button').on('click', resetImport);

    $('#preview_button').on('click', function () {
        var file = $('#import_file')[0].files[0];

        $('#import_alert').addClass('d-none').text('');

        /* İSTEMCİ TARAFI KONTROLLER SADECE DENEYİM İÇİNDİR.
         * Aynı kontroller sunucuda TEKRAR yapılır; JavaScript
         * kapatılarak atlatılabilir. */
        if (!file) {
            $('#import_alert').removeClass('d-none').text('Lütfen bir Excel dosyası seçin.');
            return;
        }

        if (!/\.xlsx$/i.test(file.name)) {
            $('#import_alert').removeClass('d-none')
                .text('Yalnızca .xlsx uzantılı dosyalar yüklenebilir. (.xls veya .csv desteklenmez)');
            return;
        }

        if (file.size > IMPORT_MAX) {
            $('#import_alert').removeClass('d-none')
                .text('Dosya boyutu en fazla ' + Math.round(IMPORT_MAX / 1024 / 1024) + ' MB olabilir.');
            return;
        }

        // FormData: Dosyayı AJAX ile gönderebilmek için gerekir.
        var payload = new FormData();
        payload.append('action', 'import_preview');
        payload.append('csrf_token', CSRF_TOKEN);
        payload.append('import_file', file);

        $('#preview_button').prop('disabled', true);
        $('#preview_spinner').removeClass('d-none');
        $('#preview_label').text('Kontrol ediliyor…');

        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            data: payload,
            contentType: false, // jQuery kendi Content-Type'ını koymasın
            processData: false, // Veriyi metne çevirmeye çalışmasın
            dataType: 'json'
        })
        .done(function (response) {
            importToken = response.token;
            renderPreview(response);
        })
        .fail(function (xhr) {
            $('#import_alert').removeClass('d-none')
                .text(errorMessage(xhr, 'Dosya okunamadı.'));
        })
        .always(function () {
            $('#preview_button').prop('disabled', false);
            $('#preview_spinner').addClass('d-none');
            $('#preview_label').text('Dosyayı Kontrol Et');
        });
    });

    /**
     * Sunucudan gelen önizlemeyi tabloya çizer.
     *
     * TÜM HÜCRELER .text() İLE DOLDURULUR.
     * Buradaki veri, kullanıcının yüklediği dosyadan gelir; yani
     * tamamen güvenilmezdir. .html() kullanılsaydı, hücresine
     * <script> yazılmış bir Excel dosyası bu sayfada kod
     * çalıştırabilirdi (XSS).
     */
    function renderPreview(response) {
        var summary = response.summary;
        var $body   = $('#import_preview_body').empty();

        /* --- Özet rozetleri --- */
        var $summary = $('#import_summary').empty();

        function badge(text, cssClass) {
            $('<span>', { 'class': 'badge ' + cssClass, text: text }).appendTo($summary);
        }

        badge('Toplam ' + summary.total + ' satır', 'text-bg-secondary');
        badge(summary.insert + ' yeni kayıt', 'text-bg-success');
        badge(summary.update + ' güncelleme', 'text-bg-primary');

        // "Değişiklik yok" satırları kaydetme adımında hiç yazılmaz
        // (bkz. system/function.php içindeki user_row_changed()).
        // Bu rozet, kullanıcıya neden bazı satırların "eksik" gibi
        // göründüğünü (aslında atlandığını) açıklar.
        if (summary.unchanged > 0) {
            badge(summary.unchanged + ' değişiklik yok', 'text-bg-light text-dark border');
        }

        if (summary.invalid > 0) {
            badge(summary.invalid + ' hatalı satır', 'text-bg-danger');
        }

        /* --- Satırlar --- */
        $.each(response.rows, function (_, row) {
            var $tr = $('<tr>');

            if (row.status === 'error') {
                $tr.addClass('cy-row-error');
            }

            $('<td>', { text: row.row }).appendTo($tr);

            var statusText = {
                insert: 'Yeni', update: 'Güncellenecek',
                unchanged: 'Değişiklik yok', error: 'Hatalı'
            }[row.status];

            var statusCss = {
                insert: 'text-bg-success', update: 'text-bg-primary',
                unchanged: 'text-bg-light text-dark border', error: 'text-bg-danger'
            }[row.status];

            $('<td>').append(
                $('<span>', { 'class': 'badge ' + statusCss, text: statusText })
            ).appendTo($tr);

            $.each(IMPORT_FIELDS, function (_, field) {
                var $td   = $('<td>');
                var value = row.values[field];

                // null ve boş değerleri tire ile göster; boş hücre
                // "veri gelmedi mi yoksa hizalama mı bozuk?" sorusunu
                // doğurur.
                $td.text((value === null || value === '') ? '—' : value);

                // Bu alanda hata varsa hücreyi işaretle ve sebebini
                // fareyle üzerine gelince göster.
                if (row.errors[field]) {
                    $td.addClass('cy-cell-error').attr('title', row.errors[field]);
                    $('<div>', { 'class': 'cy-cell-error__text', text: row.errors[field] }).appendTo($td);
                }

                $td.appendTo($tr);
            });

            $tr.appendTo($body);
        });

        /* --- Uyarı ve buton durumu --- */
        if (summary.invalid > 0) {
            $('#import_warning').removeClass('d-none').text(
                summary.invalid + ' satırda hata var ve bu satırlar AKTARILMAYACAK. '
                + 'Kaydet dediğinizde yalnızca geçerli ' + summary.valid + ' satır işlenir. '
                + 'Hatalı satırları düzeltip dosyayı tekrar yükleyebilirsiniz.'
            );
        } else {
            $('#import_warning').addClass('d-none').text('');
        }

        // Geçerli satır yoksa kaydetmenin anlamı yok.
        $('#commit_button').prop('disabled', summary.valid === 0);
        $('#commit_label').text(
            summary.valid > 0 ? summary.valid + ' Satırı Kaydet' : 'Kaydedilecek Satır Yok'
        );

        $('#import_step_file').addClass('d-none');
        $('#import_step_preview').removeClass('d-none');
        $('#preview_button').addClass('d-none');
        $('#commit_button').removeClass('d-none');
        $('#import_back_button').removeClass('d-none');
    }


    /* =============================================================
     *  3) EXCEL'DEN İÇE AKTARMA – ADIM 2: KAYDET
     * ============================================================= */
    $('#commit_button').on('click', function () {
        if (!importToken) {
            notify('Önizleme bulunamadı. Lütfen dosyayı tekrar yükleyin.', 'danger');
            return;
        }

        $('#commit_button').prop('disabled', true);
        $('#commit_spinner').removeClass('d-none');
        $('#commit_label').text('Kaydediliyor…');

        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'import_commit',
                token: importToken,
                csrf_token: CSRF_TOKEN
            }
        })
        .done(function (response) {
            importModal.hide();
            notify(response.description, 'success');
            dataTable.ajax.reload(null, false);
        })
        .fail(function (xhr) {
            notify(errorMessage(xhr, 'İçe aktarma tamamlanamadı.'), 'danger');
        })
        .always(function () {
            $('#commit_button').prop('disabled', false);
            $('#commit_spinner').addClass('d-none');
            $('#commit_label').text('Kaydet');
        });
    });


    /* =============================================================
     *  4) YENİ KAYIT BUTONU
     * ============================================================= */
    $('#add_button').on('click', function () {
        resetForm();
        $('#form_action').val('add');
        $('#userModalLabel').text('Yeni Kayıt Ekle');
        userModal.show();
    });


    /* =============================================================
     *  5) GÖRSEL SEÇİLDİĞİNDE: ÖNİZLEME + ÖN KONTROL
     * ============================================================= */
    $('#image_user').on('change', function () {
        var file = this.files && this.files[0];

        $(this).removeClass('is-invalid');
        $('[data-error-for="image_user"]').text('');

        if (!file) {
            $('#image_preview_wrapper').addClass('d-none');
            return;
        }

        if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) {
            $(this).val('').addClass('is-invalid');
            $('[data-error-for="image_user"]').text('Yalnızca JPG, PNG, GIF ve WEBP dosyaları yükleyebilirsiniz.');
            $('#image_preview_wrapper').addClass('d-none');
            return;
        }

        if (file.size > MAX_BYTES) {
            $(this).val('').addClass('is-invalid');
            $('[data-error-for="image_user"]')
                .text('Görsel boyutu en fazla ' + Math.round(MAX_BYTES / 1024 / 1024) + ' MB olabilir.');
            $('#image_preview_wrapper').addClass('d-none');
            return;
        }

        var reader = new FileReader();
        reader.onload = function (event) {
            $('#image_preview').attr('src', event.target.result);
            $('#image_preview_wrapper').removeClass('d-none');
        };
        reader.readAsDataURL(file);
    });


    /* =============================================================
     *  6) FORM GÖNDERİMİ (Ekleme ve Düzenleme ortak)
     * ============================================================= */
    $('#user_form').on('submit', function (event) {
        event.preventDefault();

        clearErrors();
        setLoading(true);

        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            dataType: 'json'
        })
        .done(function (response) {
            userModal.hide();
            resetForm();
            notify(response.description, 'success');
            dataTable.ajax.reload(null, false);
        })
        .fail(function (xhr) {
            var res = xhr.responseJSON || {};
            showErrors(res.errors, errorMessage(xhr, 'İşlem tamamlanamadı.'));
        })
        .always(function () {
            setLoading(false);
        });
    });


    /* =============================================================
     *  7) DETAY BUTONU
     * -------------------------------------------------------------
     *  Butonlar AJAX ile SONRADAN oluşturulduğu için doğrudan
     *  $('.js-view').click(...) ÇALIŞMAZ. Olayı sabit bir üst elemana
     *  bağlayıp filtreliyoruz ("event delegation").
     * ============================================================= */
    $('#user_data').on('click', '.js-view', function () {
        fetchUser($(this).data('id'), function (data) {
            currentDetailId = data.id;

            $('#detail_id').text('#' + data.id);
            $('#detail_fullname').text(data.name + ' ' + data.surname);
            $('#detail_email').text(data.email);
            $('#detail_departman').text(data.departman || '-');
            $('#detail_maas').text(data.maas_format);
            $('#detail_baslama').text(data.baslama_format);
            $('#detail_date').text(data.tarih);
            $('#detail_file').text(data.image || 'Görsel yüklenmemiş');

            if (data.image_url) {
                $('#detail_image').attr('src', data.image_url)
                                  .attr('alt', data.name + ' ' + data.surname)
                                  .removeClass('d-none');
                $('#detail_initial').addClass('d-none');
            } else {
                $('#detail_image').addClass('d-none').attr('src', '');
                $('#detail_initial').text(data.name.charAt(0).toLocaleUpperCase('tr-TR'))
                                    .removeClass('d-none');
            }

            detailModal.show();
        });
    });

    $('#detail_edit_button').on('click', function () {
        if (currentDetailId === null) { return; }

        var id = currentDetailId;
        detailModal.hide();

        // Modal kapanma animasyonu bitmeden yenisini açarsak arka plan
        // karartması üst üste biner.
        $('#detailModal').one('hidden.bs.modal', function () {
            fetchUser(id, openEditModal);
        });
    });


    /* =============================================================
     *  8) DÜZENLE BUTONU
     * ============================================================= */
    $('#user_data').on('click', '.js-edit', function () {
        fetchUser($(this).data('id'), openEditModal);
    });


    /* =============================================================
     *  9) SİL BUTONU + ONAY
     * ============================================================= */
    $('#user_data').on('click', '.js-delete', function () {
        pendingDeleteId = $(this).data('id');
        $('#delete_label').text($(this).data('label') || '#' + pendingDeleteId);
        deleteModal.show();
    });

    $('#confirm_delete').on('click', function () {
        if (pendingDeleteId === null) { return; }

        var $button = $(this).prop('disabled', true);

        $.ajax({
            url: ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: { action: 'delete', id: pendingDeleteId, csrf_token: CSRF_TOKEN }
        })
        .done(function (response) {
            notify(response.description, 'success');
            dataTable.ajax.reload(null, false);
        })
        .fail(function (xhr) {
            notify(errorMessage(xhr, 'Silme işlemi başarısız oldu.'), 'danger');
        })
        .always(function () {
            $button.prop('disabled', false);
            deleteModal.hide();
            pendingDeleteId = null;
        });
    });


    /* =============================================================
     *  10) MODAL KAPANINCA TEMİZLİK
     * ============================================================= */
    $('#userModal').on('hidden.bs.modal', resetForm);
    $('#importModal').on('hidden.bs.modal', resetImport);


    /* =============================================================
     *  11) TEMA ANAHTARI (KOYU / AÇIK)
     * -------------------------------------------------------------
     *  Marka kalıbı (cilginyazilim.css) iki kaynağa birden bakar:
     *
     *    @media (prefers-color-scheme: dark)  → sistem tercihi
     *    :root[data-cy-theme="dark"]          → kullanıcının seçimi
     *
     *  Buradaki kod YALNIZCA ikinci kaynağı yazar. Tek bir renk
     *  değeri JavaScript'te tanımlı değildir; renkler CSS'te kalır,
     *  bu dosya sadece "hangi palet" sorusunu yanıtlar.
     *
     *  ÜÇ DURUM VARDIR, İKİ DEĞİL:
     *    1) öznitelik yok      → sisteme uy (varsayılan)
     *    2) data-cy-theme=dark → her koşulda koyu
     *    3) data-cy-theme=light→ her koşulda açık
     *
     *  Sayfa ilk açıldığında öznitelik <head> içindeki küçük betikle
     *  zaten uygulanmıştır (bkz. index.php); orada yapılmasının
     *  sebebi, açık temanın bir an görünüp koyuya atlamasını
     *  ("yanıp sönme") önlemektir. Burada yalnızca butonun görünümü
     *  ilk duruma göre ayarlanır ve tıklamalar dinlenir.
     * ============================================================= */
    var $themeToggle = $('#theme_toggle');
    var systemPrefersDark = window.matchMedia
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    /** Şu anda koyu tema mı görünüyor? */
    function isDarkActive() {
        var attr = document.documentElement.getAttribute('data-cy-theme');

        if (attr === 'dark')  { return true; }
        if (attr === 'light') { return false; }

        return !!(systemPrefersDark && systemPrefersDark.matches);
    }

    /** Butonun yazısını, ikonunu ve erişilebilirlik durumunu günceller. */
    function syncThemeButton() {
        var dark = isDarkActive();

        // Buton, GİDİLECEK yönü gösterir: koyu temadayken "Açık" yazar.
        // Bulunulan durumu yazsaydı ("Koyu") kullanıcı butona basınca
        // ne olacağını tahmin edemezdi.
        $themeToggle.find('.cy-theme-toggle__icon').text(dark ? '☀' : '🌙');
        $themeToggle.find('.cy-theme-toggle__text').text(dark ? 'Açık' : 'Koyu');
        $themeToggle.attr('aria-pressed', dark ? 'true' : 'false');
        $themeToggle.attr('title', dark ? 'Açık temaya geç' : 'Koyu temaya geç');
    }

    $themeToggle.on('click', function () {
        var next = isDarkActive() ? 'light' : 'dark';

        document.documentElement.setAttribute('data-cy-theme', next);

        /* localStorage gizli sekmede veya site verileri engellendiğinde
         * yazarken istisna fırlatır. Yakalamazsak buton çalışmaz hale
         * gelir; oysa tercihin kaydedilememesi, o oturumda temanın
         * değişmesine engel değildir. */
        try {
            localStorage.setItem('cy-theme', next);
        } catch (e) {}

        syncThemeButton();
    });

    /* Kullanıcı kendi seçimini yapmadıysa sistem temasını izlemeye
     * devam ederiz: telefon akşam otomatik koyu temaya geçtiğinde
     * sayfa da geçsin. Seçim yapıldıysa öznitelik yerinde durur ve
     * bu dinleyicinin bir etkisi olmaz. */
    if (systemPrefersDark && typeof systemPrefersDark.addEventListener === 'function') {
        systemPrefersDark.addEventListener('change', function () {
            if (!document.documentElement.getAttribute('data-cy-theme')) {
                syncThemeButton();
            }
        });
    }

    syncThemeButton();
});
