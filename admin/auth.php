<?php
/* =============================================================
   Front Door DK — admin/auth.php
   ============================================================= */

// DB_HOST / DB_NAME / DB_USER / DB_PASS live in /config.php (gitignored).
// Copy config.sample.php → config.php and fill in real values.
require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'name'            => 'fd_session',
        'cookie_httponly' => true,
        'cookie_secure'   => isset($_SERVER['HTTPS']),
        'cookie_samesite' => 'Strict',
    ]);
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function require_login(): array {
    if (empty($_SESSION['fd_user'])) {
        header('Location: /admin/index.php?r=1'); exit;
    }
    if (!empty($_SESSION['fd_user']['must_change_pwd'])) {
        $cur = basename($_SERVER['PHP_SELF']);
        if (!in_array($cur, ['index.php','change-pwd.php','logout.php'])) {
            header('Location: /admin/change-pwd.php'); exit;
        }
    }
    // Comutare rapidă de limbă din bara de sus (?lang=ro|da|en) — ține minte
    // alegerea doar pentru sesiunea curentă de browser, fără să atingă
    // preferința salvată pe cont (aceea se schimbă din Setări → Profil meu,
    // vezi ui_lang()/UI_LANGS mai jos).
    if (isset($_GET['lang']) && array_key_exists($_GET['lang'], UI_LANGS)) {
        $_SESSION['fd_ui_lang'] = $_GET['lang'];
    }
    return $_SESSION['fd_user'];
}

function require_director(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') {
        header('Location: /admin/dashboard.php?err=noaccess'); exit;
    }
    return $u;
}

/* =============================================================
   LIMBA PANOULUI ADMIN — română / dansă / engleză.

   Fiecare cont are o limbă preferată salvată (bf_users.ui_lang), aleasă
   din Setări → Profil meu — asta e ce vede de la următorul login. Peste
   asta, bara de sus are un comutator rapid RO/DA/EN (?lang=xx în
   require_login() de mai sus) care schimbă limba doar pentru sesiunea
   curentă de browser, fără să atingă preferința salvată.

   Traducerea e server-side (t($cheie)), nu ascundere CSS ca pe site-ul
   public — panoul e randat de PHP, deci afișăm direct doar textul din
   limba curentă. TR conține tot ce e comun/reutilizat (navigație, butoane
   de acțiune, pagina de profil). Fiecare pagină își poate adăuga propriile
   chei aici pe măsură ce e tradusă.
   ============================================================= */

const UI_LANGS = ['ro' => 'Română', 'da' => 'Dansk', 'en' => 'English'];

function ensure_user_ui_lang_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE bf_users ADD COLUMN ui_lang VARCHAR(2) NOT NULL DEFAULT 'ro'"); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Limba curentă a interfeței: comutatorul rapid din sesiune (dacă a fost
 * folosit), altfel preferința salvată pe cont, altfel română.
 */
function ui_lang(): string {
    $sess = $_SESSION['fd_ui_lang'] ?? null;
    if ($sess && array_key_exists($sess, UI_LANGS)) return $sess;
    $acct = $_SESSION['fd_user']['ui_lang'] ?? null;
    if ($acct && array_key_exists($acct, UI_LANGS)) return $acct;
    return 'ro';
}

define('TR', [
    // ── navigație / bară de sus ──
    'nav_events'     => ['ro' => 'Evenimente', 'da' => 'Begivenheder', 'en' => 'Events'],
    'nav_projects'   => ['ro' => 'Proiecte',   'da' => 'Projekter',    'en' => 'Projects'],
    'nav_members'    => ['ro' => 'Membri',     'da' => 'Medlemmer',    'en' => 'Members'],
    'nav_topics'     => ['ro' => 'Ședințe',    'da' => 'Møder',        'en' => 'Meetings'],
    'nav_documents'  => ['ro' => 'Documente',  'da' => 'Dokumenter',   'en' => 'Documents'],
    'nav_regnskab'   => ['ro' => 'Regnskab',   'da' => 'Regnskab',     'en' => 'Regnskab'],
    'nav_settings'   => ['ro' => 'Setări',     'da' => 'Indstillinger','en' => 'Settings'],
    'logout'         => ['ro' => 'Ieși',       'da' => 'Log ud',       'en' => 'Log out'],

    // ── acțiuni comune, reutilizate în toate paginile ──
    'save'           => ['ro' => 'Salvează',        'da' => 'Gem',              'en' => 'Save'],
    'cancel'         => ['ro' => 'Anulează',        'da' => 'Annuller',         'en' => 'Cancel'],
    'delete'         => ['ro' => 'Șterge',          'da' => 'Slet',             'en' => 'Delete'],
    'edit'           => ['ro' => 'Editează',        'da' => 'Rediger',          'en' => 'Edit'],
    'add'            => ['ro' => 'Adaugă',          'da' => 'Tilføj',           'en' => 'Add'],
    'back'           => ['ro' => 'Înapoi',          'da' => 'Tilbage',          'en' => 'Back'],

    // ── Setări → Profil meu ──
    'settings_title'      => ['ro' => 'Setări',            'da' => 'Indstillinger',        'en' => 'Settings'],
    'my_profile'          => ['ro' => 'Profil meu',        'da' => 'Min profil',           'en' => 'My profile'],
    'tab_users'           => ['ro' => 'Utilizatori',       'da' => 'Brugere',              'en' => 'Users'],
    'tab_tags'            => ['ro' => 'Taguri',            'da' => 'Tags',                 'en' => 'Tags'],
    'personal_data'       => ['ro' => 'Date personale',    'da' => 'Personlige oplysninger','en' => 'Personal details'],
    'profile_photo'       => ['ro' => 'Fotografie de profil','da' => 'Profilbillede',      'en' => 'Profile photo'],
    'photo_hint'          => ['ro' => 'JPG, PNG sau WebP · max 3 MB', 'da' => 'JPG, PNG eller WebP · maks. 3 MB', 'en' => 'JPG, PNG or WebP · max 3 MB'],
    'full_name'           => ['ro' => 'Nume complet',      'da' => 'Fulde navn',           'en' => 'Full name'],
    'email'               => ['ro' => 'Email',             'da' => 'Email',                'en' => 'Email'],
    'email_locked'        => ['ro' => 'Emailul nu poate fi schimbat de aici.', 'da' => 'Emailen kan ikke ændres her.', 'en' => 'Email can\'t be changed from here.'],
    'position'            => ['ro' => 'Poziție',           'da' => 'Position',             'en' => 'Position'],
    'ui_language'         => ['ro' => 'Limba panoului',    'da' => 'Panelets sprog',       'en' => 'Panel language'],
    'ui_language_hint'    => ['ro' => 'Limba în care vezi tot panoul de administrare, de la următorul login.', 'da' => 'Sproget hele adminpanelet vises på, fra næste login.', 'en' => 'The language the whole admin panel is shown in, from your next login.'],
    'save_profile'        => ['ro' => 'Salvează profilul','da' => 'Gem profilen',         'en' => 'Save profile'],
    'change_password'     => ['ro' => 'Schimbă parola',    'da' => 'Skift adgangskode',    'en' => 'Change password'],
    'current_password'    => ['ro' => 'Parola actuală',    'da' => 'Nuværende adgangskode','en' => 'Current password'],
    'new_password'        => ['ro' => 'Parola nouă',       'da' => 'Ny adgangskode',       'en' => 'New password'],
    'confirm_new_password'=> ['ro' => 'Confirmă parola nouă','da' => 'Bekræft ny adgangskode','en' => 'Confirm new password'],
    'min_10_chars'        => ['ro' => 'Minim 10 caractere.', 'da' => 'Mindst 10 tegn.',    'en' => 'At least 10 characters.'],
    'profile_updated'     => ['ro' => 'Profilul a fost actualizat.', 'da' => 'Profilen er opdateret.', 'en' => 'Profile updated.'],

    // ── Evenimente ──
    'social_generator'    => ['ro' => 'Generator Social', 'da' => 'Social-generator', 'en' => 'Social generator'],
    'new_event'           => ['ro' => 'Eveniment nou',    'da' => 'Ny begivenhed',    'en' => 'New event'],
    'access_restricted'   => ['ro' => 'Acces restricționat.', 'da' => 'Adgang begrænset.', 'en' => 'Access restricted.'],
    'all_categories'      => ['ro' => 'Toate categoriile', 'da' => 'Alle kategorier',  'en' => 'All categories'],
    'cat_artistic'        => ['ro' => 'Artistic',  'da' => 'Kunstnerisk', 'en' => 'Artistic'],
    'cat_cultural'        => ['ro' => 'Cultural',  'da' => 'Kulturel',    'en' => 'Cultural'],
    'cat_societate'       => ['ro' => 'Societate', 'da' => 'Samfund',     'en' => 'Society'],
    'all_statuses'        => ['ro' => 'Toate statusurile', 'da' => 'Alle statusser', 'en' => 'All statuses'],
    'status_active'       => ['ro' => 'Activ',     'da' => 'Aktiv',     'en' => 'Active'],
    'status_suspended'    => ['ro' => 'Suspendat', 'da' => 'Suspenderet','en' => 'Suspended'],
    'status_cancelled'    => ['ro' => 'Anulat',    'da' => 'Aflyst',    'en' => 'Cancelled'],
    'reset_filters'       => ['ro' => 'Resetează', 'da' => 'Nulstil',   'en' => 'Reset'],
    'no_events_found'     => ['ro' => 'Niciun eveniment găsit.', 'da' => 'Ingen begivenheder fundet.', 'en' => 'No events found.'],
    'add_first'           => ['ro' => 'Adaugă primul →', 'da' => 'Tilføj den første →', 'en' => 'Add the first →'],
    'th_cover'            => ['ro' => 'Copertă', 'da' => 'Forsidebillede', 'en' => 'Cover'],
    'th_title'            => ['ro' => 'Titlu',   'da' => 'Titel',   'en' => 'Title'],
    'th_category'         => ['ro' => 'Categorie','da' => 'Kategori','en' => 'Category'],
    'th_date'             => ['ro' => 'Data',    'da' => 'Dato',    'en' => 'Date'],
    'th_status'           => ['ro' => 'Status',  'da' => 'Status',  'en' => 'Status'],
    'th_created_by'       => ['ro' => 'Creat de', 'da' => 'Oprettet af', 'en' => 'Created by'],
    'th_actions'          => ['ro' => 'Acțiuni', 'da' => 'Handlinger', 'en' => 'Actions'],
    'suspend'             => ['ro' => 'Suspendă', 'da' => 'Suspendér', 'en' => 'Suspend'],
    'reactivate'          => ['ro' => 'Reactivează', 'da' => 'Genaktivér', 'en' => 'Reactivate'],
    'cancel_event_confirm'=> ['ro' => 'Anulezi?', 'da' => 'Aflys?',   'en' => 'Cancel it?'],
    'delete_confirm'      => ['ro' => 'Ștergi definitiv?', 'da' => 'Slet permanent?', 'en' => 'Delete permanently?'],
    'view_only'           => ['ro' => 'doar vizibil', 'da' => 'kun synlig', 'en' => 'view only'],

    // ── Eveniment nou / editează ──
    'cannot_create_events'=> ['ro' => 'Nu poți crea evenimente noi.', 'da' => 'Du kan ikke oprette nye begivenheder.', 'en' => "You can't create new events."],
    'cannot_edit_event'   => ['ro' => 'Nu poți edita acest eveniment.', 'da' => 'Du kan ikke redigere denne begivenhed.', 'en' => "You can't edit this event."],
    'edit_event'          => ['ro' => 'Editează eveniment', 'da' => 'Rediger begivenhed', 'en' => 'Edit event'],
    'back_to_events'      => ['ro' => '← Înapoi la evenimente', 'da' => '← Tilbage til begivenheder', 'en' => '← Back to events'],
    'title_label'         => ['ro' => 'Titlu', 'da' => 'Titel', 'en' => 'Title'],
    'title_ro_label'      => ['ro' => 'Titlu în română', 'da' => 'Titel på rumænsk', 'en' => 'Title in Romanian'],
    'title_da_label'      => ['ro' => 'Titlu în daneză', 'da' => 'Titel på dansk', 'en' => 'Title in Danish'],
    'description_label'   => ['ro' => 'Descriere', 'da' => 'Beskrivelse', 'en' => 'Description'],
    'description_ro_label'=> ['ro' => 'Descriere în română', 'da' => 'Beskrivelse på rumænsk', 'en' => 'Description in Romanian'],
    'description_da_label'=> ['ro' => 'Descriere în daneză', 'da' => 'Beskrivelse på dansk', 'en' => 'Description in Danish'],
    'event_details'       => ['ro' => 'Detalii eveniment', 'da' => 'Begivenhedsdetaljer', 'en' => 'Event details'],
    'category_label'      => ['ro' => 'Categorie', 'da' => 'Kategori', 'en' => 'Category'],
    'location_label'      => ['ro' => 'Locație', 'da' => 'Sted', 'en' => 'Location'],
    'location_placeholder'=> ['ro' => 'ex: København, Café X', 'da' => 'fx: København, Café X', 'en' => 'e.g. Copenhagen, Café X'],
    'date_label'          => ['ro' => 'Data', 'da' => 'Dato', 'en' => 'Date'],
    'date_tbd'            => ['ro' => 'TBD', 'da' => 'TBD', 'en' => 'TBD'],
    'date_tbd_hint'       => ['ro' => 'Poți lăsa gol dacă data nu e stabilită încă (TBD) — o adaugi mai târziu.', 'da' => 'Kan stå tomt, hvis datoen ikke er fastlagt endnu (TBD) — tilføj den senere.', 'en' => 'Can be left blank if the date isn\'t set yet (TBD) — add it later.'],
    'time_optional'       => ['ro' => 'Ora (opțional)', 'da' => 'Tidspunkt (valgfrit)', 'en' => 'Time (optional)'],
    'recurring_event'     => ['ro' => 'Eveniment recurent', 'da' => 'Tilbagevendende begivenhed', 'en' => 'Recurring event'],
    'recurring_rule_label'=> ['ro' => 'Regulă recurență (opțional)', 'da' => 'Gentagelsesregel (valgfrit)', 'en' => 'Recurrence rule (optional)'],
    'recurring_rule_ph'   => ['ro' => 'ex: Lunar, a doua vineri', 'da' => 'fx: Månedligt, anden fredag', 'en' => 'e.g. Monthly, second Friday'],
    'cover_section_label' => ['ro' => 'Copertă (imagine pătrată recomandată · JPG, PNG, WebP · max 5 MB)', 'da' => 'Forsidebillede (kvadratisk billede anbefales · JPG, PNG, WebP · maks. 5 MB)', 'en' => 'Cover (square image recommended · JPG, PNG, WebP · max 5 MB)'],
    'current_image_hint'  => ['ro' => 'Imagine curentă. Urcă una nouă pentru a o înlocui.', 'da' => 'Nuværende billede. Upload et nyt for at erstatte det.', 'en' => 'Current image. Upload a new one to replace it.'],
    'tags_label'          => ['ro' => 'Taguri', 'da' => 'Tags', 'en' => 'Tags'],
    'no_tags'             => ['ro' => 'Nu există taguri.', 'da' => 'Ingen tags endnu.', 'en' => 'No tags yet.'],
    'create_arrow'        => ['ro' => 'Creează →', 'da' => 'Opret →', 'en' => 'Create →'],
    'signup_link_label'   => ['ro' => 'Link înscriere (opțional)', 'da' => 'Tilmeldingslink (valgfrit)', 'en' => 'Signup link (optional)'],
    'signup_url_label'    => ['ro' => 'URL platformă externă (minforening, Eventbrite etc.)', 'da' => 'URL til ekstern platform (minforening, Eventbrite osv.)', 'en' => 'External platform URL (minforening, Eventbrite, etc.)'],
    'signup_hint'         => ['ro' => 'Dacă e completat, apare butonul „Înscrie-te" pe site.', 'da' => 'Hvis udfyldt, vises knappen „Tilmeld dig" på hjemmesiden.', 'en' => 'If filled in, a "Sign up" button appears on the site.'],
    'add_event_btn'       => ['ro' => 'Adaugă evenimentul', 'da' => 'Tilføj begivenheden', 'en' => 'Add event'],
    'title_ro_required'   => ['ro' => 'Titlu RO obligatoriu.', 'da' => 'Titel på rumænsk er påkrævet.', 'en' => 'Romanian title is required.'],
    'title_da_required'   => ['ro' => 'Titlu DA obligatoriu.', 'da' => 'Titel på dansk er påkrævet.', 'en' => 'Danish title is required.'],
    'date_required'       => ['ro' => 'Data obligatorie.', 'da' => 'Dato er påkrævet.', 'en' => 'Date is required.'],
    'invalid_category'    => ['ro' => 'Categorie invalidă.', 'da' => 'Ugyldig kategori.', 'en' => 'Invalid category.'],
    'invalid_status'      => ['ro' => 'Status invalid.', 'da' => 'Ugyldig status.', 'en' => 'Invalid status.'],
    'image_too_large'     => ['ro' => 'Imaginea depășește 5 MB.', 'da' => 'Billedet er over 5 MB.', 'en' => 'Image exceeds 5 MB.'],
    'invalid_image_format'=> ['ro' => 'Format imagine invalid (JPG, PNG, WebP).', 'da' => 'Ugyldigt billedformat (JPG, PNG, WebP).', 'en' => 'Invalid image format (JPG, PNG, WebP).'],
    'image_save_error'    => ['ro' => 'Eroare la salvarea imaginii.', 'da' => 'Fejl ved gemning af billedet.', 'en' => 'Error saving the image.'],
    'event_added'         => ['ro' => 'Eveniment adăugat.', 'da' => 'Begivenhed tilføjet.', 'en' => 'Event added.'],
    'event_updated'       => ['ro' => 'Eveniment actualizat.', 'da' => 'Begivenhed opdateret.', 'en' => 'Event updated.'],
    'invalid_action'      => ['ro' => 'Acțiune invalidă.', 'da' => 'Ugyldig handling.', 'en' => 'Invalid action.'],
    'event_deleted'       => ['ro' => 'Evenimentul a fost șters definitiv.', 'da' => 'Begivenheden er slettet permanent.', 'en' => 'The event was permanently deleted.'],
    'event_status_reactivated' => ['ro' => 'Evenimentul a fost reactivat.', 'da' => 'Begivenheden er genaktiveret.', 'en' => 'The event was reactivated.'],
    'event_status_suspended'   => ['ro' => 'Evenimentul a fost suspendat.', 'da' => 'Begivenheden er suspenderet.', 'en' => 'The event was suspended.'],
    'event_status_cancelled'   => ['ro' => 'Evenimentul a fost anulat.', 'da' => 'Begivenheden er aflyst.', 'en' => 'The event was cancelled.'],

    // ── Proiecte — listă ──
    'new_project'         => ['ro' => 'Proiect nou', 'da' => 'Nyt projekt', 'en' => 'New project'],
    'no_projects'         => ['ro' => 'Niciun proiect.', 'da' => 'Ingen projekter.', 'en' => 'No projects.'],
    'th_order'            => ['ro' => 'Ord.', 'da' => 'Rk.', 'en' => 'Order'],
    'th_label'            => ['ro' => 'Etichetă', 'da' => 'Mærkat', 'en' => 'Label'],
    'status_draft'        => ['ro' => 'Draft', 'da' => 'Kladde', 'en' => 'Draft'],
    'status_completed'    => ['ro' => 'Finalizat', 'da' => 'Afsluttet', 'en' => 'Completed'],
    'social_short'        => ['ro' => 'Social', 'da' => 'Social', 'en' => 'Social'],
    'complete_project'    => ['ro' => 'Finalizează', 'da' => 'Afslut', 'en' => 'Complete'],
    'complete_confirm'    => ['ro' => 'Marchezi ca finalizat?', 'da' => 'Markér som afsluttet?', 'en' => 'Mark as completed?'],

    // ── Proiect nou / editează ──
    'cannot_create_projects' => ['ro' => 'Nu poți crea proiecte noi.', 'da' => 'Du kan ikke oprette nye projekter.', 'en' => "You can't create new projects."],
    'cannot_edit_project'    => ['ro' => 'Nu poți edita acest proiect.', 'da' => 'Du kan ikke redigere dette projekt.', 'en' => "You can't edit this project."],
    'edit_project'           => ['ro' => 'Editează proiect', 'da' => 'Rediger projekt', 'en' => 'Edit project'],
    'back_to_projects'       => ['ro' => '← Înapoi la proiecte', 'da' => '← Tilbage til projekter', 'en' => '← Back to projects'],
    'details_label'          => ['ro' => 'Detalii', 'da' => 'Detaljer', 'en' => 'Details'],
    'carousel_order_label'   => ['ro' => 'Ordine în carousel', 'da' => 'Rækkefølge i karrusel', 'en' => 'Carousel order'],
    'label_ro_label'         => ['ro' => 'Etichetă scurtă RO', 'da' => 'Kort mærkat (rumænsk)', 'en' => 'Short label (Romanian)'],
    'label_da_label'         => ['ro' => 'Etichetă scurtă DA', 'da' => 'Kort mærkat (dansk)', 'en' => 'Short label (Danish)'],
    'manage_tags_arrow'      => ['ro' => 'Gestionează tagurile →', 'da' => 'Administrér tags →', 'en' => 'Manage tags →'],
    'external_link_label'    => ['ro' => 'Link extern (opțional)', 'da' => 'Ekstern link (valgfrit)', 'en' => 'External link (optional)'],
    'external_url_label'     => ['ro' => 'URL platformă externă', 'da' => 'URL til ekstern platform', 'en' => 'External platform URL'],
    'appears_as_button_hint' => ['ro' => 'Apare ca buton pe cardul proiectului.', 'da' => 'Vises som knap på projektkortet.', 'en' => 'Appears as a button on the project card.'],
    'add_project_btn'        => ['ro' => 'Adaugă proiectul', 'da' => 'Tilføj projektet', 'en' => 'Add project'],
    'public_details_arrow'   => ['ro' => 'Detalii publice →', 'da' => 'Offentlige detaljer →', 'en' => 'Public details →'],
    'project_added'          => ['ro' => 'Proiect adăugat.', 'da' => 'Projekt tilføjet.', 'en' => 'Project added.'],
    'project_updated'        => ['ro' => 'Proiect actualizat.', 'da' => 'Projekt opdateret.', 'en' => 'Project updated.'],
    'project_deleted'        => ['ro' => 'Proiect șters definitiv.', 'da' => 'Projekt slettet permanent.', 'en' => 'Project permanently deleted.'],
    'project_status_completed'=> ['ro' => 'Proiectul a fost marcat finalizat.', 'da' => 'Projektet er markeret afsluttet.', 'en' => 'The project was marked completed.'],
    'project_status_cancelled'=> ['ro' => 'Proiectul a fost anulat.', 'da' => 'Projektet er aflyst.', 'en' => 'The project was cancelled.'],
    'project_status_reactivated'=> ['ro' => 'Proiectul a fost reactivat.', 'da' => 'Projektet er genaktiveret.', 'en' => 'The project was reactivated.'],

    // ── Detalii transparență proiect ──
    'details_title'          => ['ro' => 'Detalii transparență', 'da' => 'Gennemsigtighedsdetaljer', 'en' => 'Transparency details'],
    'project_word'           => ['ro' => 'Proiect', 'da' => 'Projekt', 'en' => 'Project'],
    'back_to_edit'           => ['ro' => '← Înapoi la editare', 'da' => '← Tilbage til redigering', 'en' => '← Back to editing'],
    'errors_label'           => ['ro' => 'Erori:', 'da' => 'Fejl:', 'en' => 'Errors:'],
    'view_only_details'      => ['ro' => 'Doar vizualizare — nu ai permisiunea de a edita aceste detalii.', 'da' => 'Kun visning — du har ikke tilladelse til at redigere disse detaljer.', 'en' => "View only — you don't have permission to edit these details."],
    'no_edit_details_perm'   => ['ro' => 'Nu ai permisiunea de a edita detaliile proiectului.', 'da' => 'Du har ikke tilladelse til at redigere projektdetaljerne.', 'en' => "You don't have permission to edit the project details."],
    'transparency_title_label'=> ['ro' => 'Titlu pagina transparență', 'da' => 'Titel på gennemsigtighedssiden', 'en' => 'Transparency page title'],
    'headline_ro_label'      => ['ro' => 'Titlu scurt RO', 'da' => 'Kort titel (rumænsk)', 'en' => 'Short title (Romanian)'],
    'headline_da_label'      => ['ro' => 'Titlu scurt DA', 'da' => 'Kort titel (dansk)', 'en' => 'Short title (Danish)'],
    'project_story_title'    => ['ro' => 'Povestea proiectului', 'da' => 'Projektets historie', 'en' => "The project's story"],
    'story_ro_label'         => ['ro' => 'Descriere extinsă RO', 'da' => 'Udvidet beskrivelse (rumænsk)', 'en' => 'Extended description (Romanian)'],
    'story_da_label'         => ['ro' => 'Descriere extinsă DA', 'da' => 'Udvidet beskrivelse (dansk)', 'en' => 'Extended description (Danish)'],
    'budget_title'           => ['ro' => 'Buget (DKK)', 'da' => 'Budget (DKK)', 'en' => 'Budget (DKK)'],
    'budget_needed_label'    => ['ro' => 'Buget necesar total', 'da' => 'Samlet nødvendigt budget', 'en' => 'Total budget needed'],
    'budget_needed_hint'     => ['ro' => 'Lasă gol dacă nu e relevant.', 'da' => 'Lad stå tomt hvis ikke relevant.', 'en' => 'Leave blank if not relevant.'],
    'budget_raised_label'    => ['ro' => 'Buget strâns până acum', 'da' => 'Budget indsamlet indtil nu', 'en' => 'Budget raised so far'],
    'funded_label'           => ['ro' => 'finanțat', 'da' => 'finansieret', 'en' => 'funded'],
    'expense_breakdown_ro_label'=> ['ro' => 'Detaliere cheltuieli RO', 'da' => 'Udgiftsfordeling (rumænsk)', 'en' => 'Expense breakdown (Romanian)'],
    'expense_breakdown_da_label'=> ['ro' => 'Detaliere cheltuieli DA', 'da' => 'Udgiftsfordeling (dansk)', 'en' => 'Expense breakdown (Danish)'],
    'photo_gallery_title'    => ['ro' => 'Galerie foto (max 4 poze)', 'da' => 'Fotogalleri (maks. 4 billeder)', 'en' => 'Photo gallery (max 4 photos)'],
    'delete_photo_label'     => ['ro' => 'Șterge foto', 'da' => 'Slet foto', 'en' => 'Delete photo'],
    'photo_word'             => ['ro' => 'Foto', 'da' => 'Foto', 'en' => 'Photo'],
    'max_5mb'                => ['ro' => 'Max 5MB', 'da' => 'Maks. 5MB', 'en' => 'Max 5MB'],
    'save_details_btn'       => ['ro' => 'Salvează detaliile', 'da' => 'Gem detaljerne', 'en' => 'Save details'],
    'donors_title'           => ['ro' => 'Finanțare', 'da' => 'Finansiering', 'en' => 'Financing'],
    'th_name'                => ['ro' => 'Nume / sursă', 'da' => 'Navn / kilde', 'en' => 'Name / source'],
    'th_type'                => ['ro' => 'Tip', 'da' => 'Type', 'en' => 'Type'],
    'th_method'              => ['ro' => 'Modalitate', 'da' => 'Metode', 'en' => 'Method'],
    'th_details'             => ['ro' => 'Detalii', 'da' => 'Detaljer', 'en' => 'Details'],
    'th_value'               => ['ro' => 'Valoare', 'da' => 'Værdi', 'en' => 'Value'],
    'th_reference'           => ['ro' => 'Referință', 'da' => 'Reference', 'en' => 'Reference'],
    'delete_donor_confirm'   => ['ro' => 'Ștergi această intrare de finanțare?', 'da' => 'Slet denne finansieringspost?', 'en' => 'Delete this financing entry?'],
    'no_contributions'       => ['ro' => 'Nicio intrare de finanțare înregistrată încă.', 'da' => 'Ingen finansieringspost registreret endnu.', 'en' => 'No financing entries recorded yet.'],
    'donor_name_label'       => ['ro' => 'Nume / sursă', 'da' => 'Navn / kilde', 'en' => 'Name / source'],
    'donor_name_ph'          => ['ro' => 'Lasă gol pentru anonim', 'da' => 'Lad stå tomt for anonym', 'en' => 'Leave blank for anonymous'],
    'type_extern'            => ['ro' => 'Extern', 'da' => 'Ekstern', 'en' => 'External'],
    'type_membru'            => ['ro' => 'Membru asociație', 'da' => 'Foreningsmedlem', 'en' => 'Association member'],
    'type_anonim'            => ['ro' => 'Anonim', 'da' => 'Anonym', 'en' => 'Anonymous'],
    'method_transfer'        => ['ro' => 'Transfer bancar', 'da' => 'Bankoverførsel', 'en' => 'Bank transfer'],
    'method_bunuri'          => ['ro' => 'Bunuri / Servicii', 'da' => 'Varer / Tjenester', 'en' => 'Goods / Services'],
    'method_voluntariat'     => ['ro' => 'Ore voluntariat', 'da' => 'Frivillige timer', 'en' => 'Volunteer hours'],
    'estimated_value_label'  => ['ro' => 'Valoare estimată (DKK)', 'da' => 'Anslået værdi (DKK)', 'en' => 'Estimated value (DKK)'],
    'optional_ph'            => ['ro' => 'opțional', 'da' => 'valgfrit', 'en' => 'optional'],
    'donor_details_ph'       => ['ro' => 'ex: sală gratuită 3h, 20 ore design, transport materiale', 'da' => 'fx: gratis lokale 3t, 20 timers design, materialetransport', 'en' => 'e.g. free venue 3h, 20 design hours, materials transport'],
    'add_contribution_btn'   => ['ro' => '+ Adaugă finanțare', 'da' => '+ Tilføj finansiering', 'en' => '+ Add financing'],
    'donor_added'            => ['ro' => 'Finanțare adăugată.', 'da' => 'Finansiering tilføjet.', 'en' => 'Financing added.'],
    'donor_deleted'          => ['ro' => 'Finanțare ștearsă.', 'da' => 'Finansiering slettet.', 'en' => 'Financing deleted.'],
    // ── tip de intrare de finanțare (nou) ──
    'entry_type_label'       => ['ro' => 'Tip intrare', 'da' => 'Posttype', 'en' => 'Entry type'],
    'entry_type_donatie'     => ['ro' => 'Donație', 'da' => 'Donation', 'en' => 'Donation'],
    'entry_type_contributie' => ['ro' => 'Contribuție', 'da' => 'Bidrag', 'en' => 'Contribution'],
    'entry_type_linie'       => ['ro' => 'Linie de finanțare', 'da' => 'Finansieringslinje', 'en' => 'Funding line'],
    'entry_type_grant'       => ['ro' => 'Grant', 'da' => 'Tilskud', 'en' => 'Grant'],
    'entry_type_sponsorizare'=> ['ro' => 'Sponsorizare', 'da' => 'Sponsorat', 'en' => 'Sponsorship'],
    'entry_type_cotizatie'   => ['ro' => 'Cotizație membri', 'da' => 'Medlemskontingent', 'en' => 'Membership dues'],
    'entry_type_custom'      => ['ro' => 'Altceva (specifică)', 'da' => 'Andet (angiv)', 'en' => 'Other (specify)'],
    'entry_type_custom_label'=> ['ro' => 'Specifică tipul', 'da' => 'Angiv typen', 'en' => 'Specify the type'],
    'entry_type_custom_ph'   => ['ro' => 'ex: rambursare cheltuieli', 'da' => 'fx: udgiftsrefusion', 'en' => 'e.g. expense reimbursement'],
    'funder_name_label'      => ['ro' => 'Instituție / program finanțator', 'da' => 'Finansierende institution / program', 'en' => 'Funding institution / program'],
    'funder_name_ph'         => ['ro' => 'ex: Ministerul Culturii, Fonden for...', 'da' => 'fx: Kulturministeriet, Fonden for...', 'en' => 'e.g. Ministry of Culture, Fonden for...'],
    'reference_number_label' => ['ro' => 'Nr. contract / referință', 'da' => 'Kontrakt-/referencenr.', 'en' => 'Contract / reference no.'],
    'period_start_label'     => ['ro' => 'Perioadă finanțare — început', 'da' => 'Finansieringsperiode — start', 'en' => 'Financing period — start'],
    'period_end_label'       => ['ro' => 'Perioadă finanțare — sfârșit', 'da' => 'Finansieringsperiode — slut', 'en' => 'Financing period — end'],
    'financing_extra_hint'   => ['ro' => 'Aceste câmpuri apar doar pentru Grant și Linie de finanțare.', 'da' => 'Disse felter vises kun for Tilskud og Finansieringslinje.', 'en' => 'These fields only appear for Grant and Funding line.'],
    'details_updated'        => ['ro' => 'Detalii actualizate.', 'da' => 'Detaljer opdateret.', 'en' => 'Details updated.'],
    'photo_upload_error'     => ['ro' => 'Eroare upload foto', 'da' => 'Fejl ved upload af foto', 'en' => 'Error uploading photo'],
    'exceeds_5mb'            => ['ro' => 'depășește 5MB', 'da' => 'er over 5MB', 'en' => 'exceeds 5MB'],
    'jpg_png_webp_only'      => ['ro' => 'doar JPG/PNG/WEBP', 'da' => 'kun JPG/PNG/WEBP', 'en' => 'JPG/PNG/WEBP only'],
    'could_not_save_photo'   => ['ro' => 'Nu s-a putut salva foto', 'da' => 'Kunne ikke gemme foto', 'en' => 'Could not save photo'],

    // ── Membri — cereri membership ──
    'membership_requests'    => ['ro' => 'Cereri membership', 'da' => 'Medlemsansøgninger', 'en' => 'Membership requests'],
    'membership_requests_h1' => ['ro' => 'Cereri de membership', 'da' => 'Medlemsansøgninger', 'en' => 'Membership requests'],
    'export_pdf_active'      => ['ro' => 'Export PDF membri activi', 'da' => 'Eksportér PDF, aktive medlemmer', 'en' => 'Export PDF, active members'],
    'public_form_link'       => ['ro' => 'Formularul public →', 'da' => 'Den offentlige formular →', 'en' => 'Public form →'],
    'tab_all'                => ['ro' => 'Toate', 'da' => 'Alle', 'en' => 'All'],
    'status_new'             => ['ro' => 'Nou', 'da' => 'Ny', 'en' => 'New'],
    'status_contacted'       => ['ro' => 'Contactat', 'da' => 'Kontaktet', 'en' => 'Contacted'],
    'status_pending'         => ['ro' => 'În așteptare', 'da' => 'Afventer', 'en' => 'Pending'],
    'status_declined'        => ['ro' => 'Refuzat', 'da' => 'Afvist', 'en' => 'Declined'],
    'no_new_requests'        => ['ro' => 'Nicio cerere nouă.', 'da' => 'Ingen nye ansøgninger.', 'en' => 'No new requests.'],
    'no_requests_category'   => ['ro' => 'Nicio cerere în această categorie.', 'da' => 'Ingen ansøgninger i denne kategori.', 'en' => 'No requests in this category.'],
    'exempt_badge'           => ['ro' => 'Scutit', 'da' => 'Fritaget', 'en' => 'Exempt'],
    'dues_paid'              => ['ro' => 'Cotizație plătită', 'da' => 'Kontingent betalt', 'en' => 'Dues paid'],
    'dues_expired'           => ['ro' => 'Cotizație expirată', 'da' => 'Kontingent udløbet', 'en' => 'Dues expired'],
    'volunteer_badge'        => ['ro' => 'Voluntar', 'da' => 'Frivillig', 'en' => 'Volunteer'],
    'special_needs_badge'    => ['ro' => 'Nevoi speciale', 'da' => 'Særlige behov', 'en' => 'Special needs'],
    'deactivated_suffix'     => ['ro' => ' · dezactivat', 'da' => ' · deaktiveret', 'en' => ' · deactivated'],
    'association_email_suffix'=> ['ro' => ' (email de asociație)', 'da' => ' (foreningsmail)', 'en' => ' (association email)'],
    'profile_btn'            => ['ro' => 'Profil', 'da' => 'Profil', 'en' => 'Profile'],
    'write_email_btn'        => ['ro' => 'Scrie email', 'da' => 'Skriv email', 'en' => 'Write email'],
    'status_btn'             => ['ro' => 'Status', 'da' => 'Status', 'en' => 'Status'],
    'internal_notes_label'   => ['ro' => 'Note interne', 'da' => 'Interne noter', 'en' => 'Internal notes'],
    'internal_notes_ph'      => ['ro' => 'ex: Contactat 31.08, așteptăm confirmare...', 'da' => 'fx: Kontaktet 31.08, afventer bekræftelse...', 'en' => 'e.g. Contacted Aug 31, awaiting confirmation...'],
    'email_to_prefix'        => ['ro' => 'Email către', 'da' => 'Email til', 'en' => 'Email to'],
    'quick_template_label'   => ['ro' => 'Șablon rapid', 'da' => 'Hurtig skabelon', 'en' => 'Quick template'],
    'choose_template_ph'     => ['ro' => '— Alege un șablon —', 'da' => '— Vælg en skabelon —', 'en' => '— Choose a template —'],
    'tmpl_confirm_received'  => ['ro' => 'Cerere primită', 'da' => 'Ansøgning modtaget', 'en' => 'Application received'],
    'tmpl_confirm_active'    => ['ro' => 'Membership activ', 'da' => 'Aktivt medlemskab', 'en' => 'Active membership'],
    'tmpl_info_payment'      => ['ro' => 'Informații cotizație', 'da' => 'Kontingentoplysninger', 'en' => 'Membership fee info'],
    'tmpl_pending_info'      => ['ro' => 'În așteptare', 'da' => 'Afventer', 'en' => 'Pending'],
    'tmpl_blank'             => ['ro' => 'Mesaj liber', 'da' => 'Fri besked', 'en' => 'Free message'],
    'template_lang_title'    => ['ro' => 'Limba șablonului', 'da' => 'Skabelonens sprog', 'en' => 'Template language'],
    'subject_label'          => ['ro' => 'Subiect', 'da' => 'Emne', 'en' => 'Subject'],
    'subject_ph'             => ['ro' => 'Subiectul emailului...', 'da' => 'Emailens emne...', 'en' => "Email's subject..."],
    'message_label'          => ['ro' => 'Mesaj', 'da' => 'Besked', 'en' => 'Message'],
    'message_ph'             => ['ro' => 'Scrie mesajul...', 'da' => 'Skriv beskeden...', 'en' => 'Write the message...'],
    'from_label'             => ['ro' => 'De la:', 'da' => 'Fra:', 'en' => 'From:'],
    'signature_auto_hint'    => ['ro' => 'Semnătura se adaugă automat.', 'da' => 'Signaturen tilføjes automatisk.', 'en' => 'The signature is added automatically.'],
    'send_btn'               => ['ro' => 'Trimite', 'da' => 'Send', 'en' => 'Send'],
    'status_updated_prefix'  => ['ro' => 'Status actualizat: ', 'da' => 'Status opdateret: ', 'en' => 'Status updated: '],
    'subject_body_required'  => ['ro' => 'Subiect și mesaj sunt obligatorii.', 'da' => 'Emne og besked er påkrævet.', 'en' => 'Subject and message are required.'],
    'email_sent_prefix'      => ['ro' => 'Email trimis cu succes către ', 'da' => 'Email sendt til ', 'en' => 'Email successfully sent to '],
    'smtp_error_prefix'      => ['ro' => 'Eroare SMTP: ', 'da' => 'SMTP-fejl: ', 'en' => 'SMTP error: '],
    'request_deleted'        => ['ro' => 'Cerere ștearsă.', 'da' => 'Ansøgning slettet.', 'en' => 'Request deleted.'],

    // ── Export membri ──
    'export_members'         => ['ro' => 'Export membri', 'da' => 'Eksportér medlemmer', 'en' => 'Export members'],
    'back_to_requests'       => ['ro' => '← Înapoi la cereri', 'da' => '← Tilbage til ansøgninger', 'en' => '← Back to requests'],
    'export_active_list_h1'  => ['ro' => 'Export listă membri activi', 'da' => 'Eksportér liste over aktive medlemmer', 'en' => 'Export active members list'],
    'export_intro'           => ['ro' => 'Exportul include doar membrii cu statusul <strong>Activ</strong>. Alege ce coloane vrei în document — de exemplu doar cele cerute de comună (nume, adresă, vârstă) pentru medlemsliste, sau tot ce ține de cotizație pentru evidența internă.',
                                  'da' => 'Eksporten omfatter kun medlemmer med status <strong>Aktiv</strong>. Vælg hvilke kolonner du vil have med i dokumentet — fx kun dem kommunen kræver (navn, adresse, alder) til medlemslisten, eller alt om kontingent til intern brug.',
                                  'en' => 'The export only includes members with <strong>Active</strong> status. Choose which columns to include — for example just the ones the municipality requires (name, address, age) for the member list, or everything related to dues for internal records.'],
    'kommune_preset_btn'     => ['ro' => 'Doar câmpurile cerute de comună (medlemsliste)', 'da' => 'Kun de felter kommunen kræver (medlemsliste)', 'en' => 'Only the fields the municipality requires (member list)'],
    'fields_to_include'      => ['ro' => 'Câmpuri de inclus', 'da' => 'Felter der skal medtages', 'en' => 'Fields to include'],
    'generate_arrow'         => ['ro' => 'Generează →', 'da' => 'Generér →', 'en' => 'Generate →'],
    'field_member_number'    => ['ro' => 'Nr. membru', 'da' => 'Medlemsnr.', 'en' => 'Member no.'],
    'field_name'             => ['ro' => 'Nume', 'da' => 'Navn', 'en' => 'Name'],
    'field_email'            => ['ro' => 'Email', 'da' => 'Email', 'en' => 'Email'],
    'field_phone'            => ['ro' => 'Telefon', 'da' => 'Telefon', 'en' => 'Phone'],
    'field_birth_date'       => ['ro' => 'Dată naștere', 'da' => 'Fødselsdato', 'en' => 'Date of birth'],
    'field_age'              => ['ro' => 'Vârstă (la 31.12)', 'da' => 'Alder (pr. 31.12)', 'en' => 'Age (as of Dec 31)'],
    'field_gender'           => ['ro' => 'Sex', 'da' => 'Køn', 'en' => 'Gender'],
    'field_address'          => ['ro' => 'Adresă', 'da' => 'Adresse', 'en' => 'Address'],
    'field_postal_code'      => ['ro' => 'Cod poștal', 'da' => 'Postnummer', 'en' => 'Postal code'],
    'field_city'             => ['ro' => 'Oraș', 'da' => 'By', 'en' => 'City'],
    'field_special_needs'    => ['ro' => 'Nevoi speciale', 'da' => 'Særlige behov', 'en' => 'Special needs'],
    'field_special_needs_note'=> ['ro' => 'Notă nevoi speciale', 'da' => 'Note (særlige behov)', 'en' => 'Special needs note'],
    'field_joined_date'      => ['ro' => 'Dată aderare', 'da' => 'Indmeldelsesdato', 'en' => 'Date joined'],
    'field_dues_paid'        => ['ro' => 'Cotizație plătită', 'da' => 'Kontingent betalt', 'en' => 'Dues paid'],
    'field_dues_paid_date'   => ['ro' => 'Data plății', 'da' => 'Betalingsdato', 'en' => 'Payment date'],
    'field_dues_valid_until' => ['ro' => 'Valabil până la', 'da' => 'Gyldig til', 'en' => 'Valid until'],
    'field_dues_amount'      => ['ro' => 'Sumă plătită (DKK)', 'da' => 'Beløb (DKK)', 'en' => 'Amount paid (DKK)'],
    'field_dues_method'      => ['ro' => 'Metodă plată', 'da' => 'Betalingsmetode', 'en' => 'Payment method'],
    'field_exempt'           => ['ro' => 'Scutit', 'da' => 'Fritaget', 'en' => 'Exempt'],
    'field_exempt_reason'    => ['ro' => 'Motiv scutire', 'da' => 'Årsag til fritagelse', 'en' => 'Exemption reason'],
    'field_is_volunteer'     => ['ro' => 'Voluntar', 'da' => 'Frivillig', 'en' => 'Volunteer'],
    'field_notes'            => ['ro' => 'Note interne', 'da' => 'Interne noter', 'en' => 'Internal notes'],

    // ── Profil membru ──
    'member_not_found'       => ['ro' => 'Membru inexistent.', 'da' => 'Medlem findes ikke.', 'en' => 'Member not found.'],
    'no_edit_profile_perm'   => ['ro' => 'Nu ai permisiunea de a edita acest profil.', 'da' => 'Du har ikke tilladelse til at redigere denne profil.', 'en' => "You don't have permission to edit this profile."],
    'name_required'          => ['ro' => 'Numele e obligatoriu.', 'da' => 'Navn er påkrævet.', 'en' => 'Name is required.'],
    'invalid_email'          => ['ro' => 'Email invalid.', 'da' => 'Ugyldig email.', 'en' => 'Invalid email.'],
    'invalid_secondary_email'=> ['ro' => 'Email de asociație invalid.', 'da' => 'Ugyldig foreningsmail.', 'en' => 'Invalid association email.'],
    'login_email_sync_failed'=> ['ro' => ' Emailul de login NU a putut fi sincronizat automat — există deja alt cont cu „', 'da' => ' Login-emailen kunne IKKE synkroniseres automatisk — der findes allerede en anden konto med „', 'en' => ' The login email could NOT be synced automatically — another account already uses „'],
    'login_email_synced'     => ['ro' => ' Emailul de login a fost actualizat automat la ', 'da' => ' Login-emailen blev automatisk opdateret til ', 'en' => ' The login email was automatically updated to '],
    'contribution_added'     => ['ro' => 'Proiect adăugat la contribuții.', 'da' => 'Projekt tilføjet til bidrag.', 'en' => 'Project added to contributions.'],
    'contribution_status_updated'=> ['ro' => 'Status contribuție actualizat.', 'da' => 'Bidragsstatus opdateret.', 'en' => 'Contribution status updated.'],
    'contribution_removed'   => ['ro' => 'Contribuție eliminată.', 'da' => 'Bidrag fjernet.', 'en' => 'Contribution removed.'],
    'invalid_position'       => ['ro' => 'Poziție invalidă.', 'da' => 'Ugyldig position.', 'en' => 'Invalid position.'],
    'account_exists_for_email'=> ['ro' => 'Există deja un cont pentru acest email.', 'da' => 'Der findes allerede en konto med denne email.', 'en' => 'An account already exists for this email.'],
    'panel_limit_reached'    => ['ro' => 'Limita de ', 'da' => 'Grænsen på ', 'en' => 'The limit of '],
    'panel_limit_reached_suffix'=> ['ro' => ' conturi în panoul admin a fost atinsă.', 'da' => ' konti i adminpanelet er nået.', 'en' => ' accounts in the admin panel has been reached.'],
    'account_created_prefix' => ['ro' => 'Cont creat — ', 'da' => 'Konto oprettet — ', 'en' => 'Account created — '],
    'login_label_paren'      => ['ro' => ' (login: ', 'da' => ' (login: ', 'en' => ' (login: '],
    'initial_password_label' => ['ro' => '). Parolă inițială: ', 'da' => '). Startadgangskode: ', 'en' => '). Initial password: '],
    'replaced_auto_prefix'   => ['ro' => ' a fost înlocuit automat — contul lui a fost șters (ocupa aceeași poziție).', 'da' => ' blev automatisk erstattet — kontoen er slettet (havde samme position).', 'en' => ' was automatically replaced — their account was deleted (held the same position).'],
    'welcome_email_sent'     => ['ro' => ' Email de welcome trimis.', 'da' => ' Velkomstemail sendt.', 'en' => ' Welcome email sent.'],
    'email_send_error_prefix'=> ['ro' => ' Eroare la trimiterea emailului: ', 'da' => ' Fejl ved afsendelse af email: ', 'en' => ' Error sending the email: '],
    'invalid_or_linked_account'=> ['ro' => 'Cont invalid sau deja conectat la alt profil.', 'da' => 'Ugyldig konto eller allerede forbundet til en anden profil.', 'en' => 'Invalid account, or already linked to another profile.'],
    'account_linked_prefix'  => ['ro' => 'Contul „', 'da' => 'Kontoen „', 'en' => 'The account „'],
    'account_linked_suffix'  => ['ro' => '" a fost conectat la acest profil.', 'da' => '" er forbundet til denne profil.', 'en' => '" was linked to this profile.'],
    'duplicate_account_deleted_prefix'=> ['ro' => ' Contul duplicat (', 'da' => ' Den duplikerede konto (', 'en' => ' The duplicate account ('],
    'duplicate_account_deleted_suffix'=> ['ro' => ') a fost șters.', 'da' => ') er slettet.', 'en' => ') was deleted.'],
    'avatar_pulled_from_account'=> ['ro' => ' Poza de profil a fost preluată din cont.', 'da' => ' Profilbilledet blev hentet fra kontoen.', 'en' => ' The profile photo was pulled from the account.'],
    'could_not_reactivate_limit'=> ['ro' => ' Nu a putut fi reactivat automat (limita de ', 'da' => ' Kunne ikke genaktiveres automatisk (grænsen på ', 'en' => ' Could not be reactivated automatically (the limit of '],
    'accounts_reached_paren'  => ['ro' => ' conturi e atinsă).', 'da' => ' konti er nået).', 'en' => ' accounts has been reached).'],
    'could_not_reactivate_reason'=> ['ro' => ' Nu a putut fi reactivat automat (', 'da' => ' Kunne ikke genaktiveres automatisk (', 'en' => ' Could not be reactivated automatically ('],
    'account_reactivated'    => ['ro' => ' Contul a fost reactivat.', 'da' => ' Kontoen er genaktiveret.', 'en' => ' The account was reactivated.'],
    'no_panel_account'       => ['ro' => 'Acest membru nu are cont în panoul admin.', 'da' => 'Dette medlem har ikke en konto i adminpanelet.', 'en' => "This member doesn't have an admin panel account."],
    'password_reset_to'      => ['ro' => 'Parolă resetată la: ', 'da' => 'Adgangskode nulstillet til: ', 'en' => 'Password reset to: '],
    'email_resent'           => ['ro' => ' Email retrimis.', 'da' => ' Email gensendt.', 'en' => ' Email resent.'],
    'could_not_create_account'=> ['ro' => 'Nu s-a putut crea contul (email deja folosit?).', 'da' => 'Kontoen kunne ikke oprettes (email allerede i brug?).', 'en' => 'Could not create the account (email already in use?).'],
    'member_profile_title'   => ['ro' => 'Profil membru', 'da' => 'Medlemsprofil', 'en' => 'Member profile'],
    'view_only_profile'      => ['ro' => 'Doar vizualizare — nu ai permisiunea de a edita acest profil.', 'da' => 'Kun visning — du har ikke tilladelse til at redigere denne profil.', 'en' => "View only — you don't have permission to edit this profile."],
    'profile_photo_section'  => ['ro' => 'Fotografie de profil', 'da' => 'Profilbillede', 'en' => 'Profile photo'],
    'contact_data_section'   => ['ro' => 'Date de contact', 'da' => 'Kontaktoplysninger', 'en' => 'Contact details'],
    'name_field'             => ['ro' => 'Nume', 'da' => 'Navn', 'en' => 'Name'],
    'personal_email_field'   => ['ro' => 'Email personal', 'da' => 'Personlig email', 'en' => 'Personal email'],
    'association_email_field'=> ['ro' => 'Email de asociație', 'da' => 'Foreningsmail', 'en' => 'Association email'],
    'association_email_hint' => ['ro' => 'Opțional. Dacă e completat, devine automat adresa principală — folosită pentru orice email (welcome, resetare parolă, corespondență) și pentru login în panoul admin, dacă membrul are deja un cont conectat.', 'da' => 'Valgfrit. Hvis udfyldt, bliver den automatisk hovedadressen — brugt til alle emails (velkomst, nulstilling af adgangskode, korrespondance) og til login i adminpanelet, hvis medlemmet allerede har en forbundet konto.', 'en' => "Optional. If filled in, it automatically becomes the primary address — used for all emails (welcome, password reset, correspondence) and for admin panel login, if the member already has a linked account."],
    'phone_field'            => ['ro' => 'Telefon', 'da' => 'Telefon', 'en' => 'Phone'],
    'city_field'             => ['ro' => 'Oraș', 'da' => 'By', 'en' => 'City'],
    'request_status_field'   => ['ro' => 'Status cerere', 'da' => 'Ansøgningsstatus', 'en' => 'Request status'],
    'member_section'         => ['ro' => 'Membru', 'da' => 'Medlem', 'en' => 'Member'],
    'member_number_field'    => ['ro' => 'Număr membru', 'da' => 'Medlemsnummer', 'en' => 'Member number'],
    'joined_date_field'      => ['ro' => 'Dată aderare', 'da' => 'Indmeldelsesdato', 'en' => 'Date joined'],
    'kommune_report_section' => ['ro' => 'Date pentru raportare la comună', 'da' => 'Data til indberetning til kommunen', 'en' => 'Data for municipality reporting'],
    'kommune_report_hint'    => ['ro' => 'Cerute de Københavns Kommune pentru medlemsliste (folkeoplysningsloven) — nume, adresă și dată de naștere pentru toți membrii, plus o declarație pentru tilskud suplimentar la membrii cu nevoi speciale.', 'da' => 'Kræves af Københavns Kommune til medlemslisten (folkeoplysningsloven) — navn, adresse og fødselsdato for alle medlemmer, plus en erklæring for ekstra tilskud til medlemmer med særlige behov.', 'en' => "Required by Copenhagen Municipality for the member list (folkeoplysningsloven) — name, address and date of birth for all members, plus a declaration for extra funding for members with special needs."],
    'birth_date_field'       => ['ro' => 'Data nașterii', 'da' => 'Fødselsdato', 'en' => 'Date of birth'],
    'age_at_dec31'           => ['ro' => ' ani la 31.12.', 'da' => ' år pr. 31.12.', 'en' => ' years old as of Dec 31, '],
    'gender_field'           => ['ro' => 'Sex', 'da' => 'Køn', 'en' => 'Gender'],
    'postal_code_field'      => ['ro' => 'Cod poștal', 'da' => 'Postnummer', 'en' => 'Postal code'],
    'address_field'          => ['ro' => 'Adresă (stradă, nr.)', 'da' => 'Adresse (vej, nr.)', 'en' => 'Address (street, no.)'],
    'address_hint'           => ['ro' => 'Orașul de mai sus + codul poștal formează bopælskommune-ul folosit de comună.', 'da' => 'Byen ovenfor + postnummeret danner den bopælskommune, kommunen bruger.', 'en' => 'The city above + the postal code form the bopælskommune used by the municipality.'],
    'special_needs_checkbox' => ['ro' => 'Membru cu nevoi speciale / handicap', 'da' => 'Medlem med særlige behov / handicap', 'en' => 'Member with special needs / disability'],
    'note_field'             => ['ro' => 'Notă', 'da' => 'Note', 'en' => 'Note'],
    'special_needs_note_hint'=> ['ro' => 'Pentru tilskud suplimentar comuna cere o Tro- og love-erklæring semnată de membru — se păstrează separat pe hârtie/PDF, aici doar bifa + notă internă.', 'da' => 'Til ekstra tilskud kræver kommunen en Tro- og love-erklæring underskrevet af medlemmet — den opbevares separat på papir/PDF, her er kun afkrydsning + intern note.', 'en' => 'For extra funding the municipality requires a signed declaration from the member — kept separately on paper/PDF; here it is just the checkbox + internal note.'],
    'dues_section'           => ['ro' => 'Cotizație', 'da' => 'Kontingent', 'en' => 'Membership fee'],
    'dues_paid_checkbox'     => ['ro' => 'Cotizația a fost plătită', 'da' => 'Kontingentet er betalt', 'en' => 'The fee has been paid'],
    'payment_date_field'     => ['ro' => 'Data plății', 'da' => 'Betalingsdato', 'en' => 'Payment date'],
    'valid_until_field'      => ['ro' => 'Valabil până la', 'da' => 'Gyldig til', 'en' => 'Valid until'],
    'valid_until_hint'       => ['ro' => 'Dacă rămâne gol și bifezi „plătită" cu o dată, presupunem 1 an de la plată.', 'da' => 'Hvis den står tom, og du markerer „betalt" med en dato, antager vi 1 år fra betalingen.', 'en' => 'If left blank and you check "paid" with a date, we assume 1 year from the payment.'],
    'amount_paid_field'      => ['ro' => 'Sumă plătită (DKK)', 'da' => 'Betalt beløb (DKK)', 'en' => 'Amount paid (DKK)'],
    'payment_method_field'   => ['ro' => 'Metodă de plată', 'da' => 'Betalingsmetode', 'en' => 'Payment method'],
    'exempt_checkbox'        => ['ro' => 'Scutit de cotizație', 'da' => 'Fritaget for kontingent', 'en' => 'Exempt from fees'],
    'exempt_reason_field'    => ['ro' => 'Motiv scutire', 'da' => 'Årsag til fritagelse', 'en' => 'Exemption reason'],
    'exempt_reason_ph'       => ['ro' => 'ex: fondator, dificultăți financiare, minor...', 'da' => 'fx: stifter, økonomiske vanskeligheder, mindreårig...', 'en' => 'e.g. founder, financial hardship, minor...'],
    'relations_section'      => ['ro' => 'Relații', 'da' => 'Relationer', 'en' => 'Relations'],
    'relation_field'         => ['ro' => 'Relație', 'da' => 'Relation', 'en' => 'Relation'],
    'related_member_field'   => ['ro' => 'Membru relaționat', 'da' => 'Relateret medlem', 'en' => 'Related member'],
    'choose_ellipsis'        => ['ro' => '— Alege —', 'da' => '— Vælg —', 'en' => '— Choose —'],
    'reverse_related_to'     => ['ro' => 'Relaționat (invers) cu:', 'da' => 'Relateret (omvendt) til:', 'en' => 'Related (reverse) to:'],
    'volunteering_section'   => ['ro' => 'Voluntariat', 'da' => 'Frivilligt arbejde', 'en' => 'Volunteering'],
    'is_volunteer_checkbox'  => ['ro' => 'Este și voluntar în asociație', 'da' => 'Er også frivillig i foreningen', 'en' => 'Is also a volunteer in the association'],
    'give_up'                => ['ro' => 'Renunță', 'da' => 'Annuller', 'en' => 'Discard'],
    'admin_panel_account_section'=> ['ro' => 'Cont panou admin', 'da' => 'Adminpanel-konto', 'en' => 'Admin panel account'],
    'already_has_account_prefix'=> ['ro' => 'Acest membru are deja cont în panoul admin —', 'da' => 'Dette medlem har allerede en konto i adminpanelet —', 'en' => 'This member already has an admin panel account —'],
    'login_with'             => ['ro' => 'login cu', 'da' => 'login med', 'en' => 'login with'],
    'deactivated_paren'      => ['ro' => ' (dezactivat)', 'da' => ' (deaktiveret)', 'en' => ' (deactivated)'],
    'granular_access_edit_hint_prefix'=> ['ro' => 'Accesul granular se editează din', 'da' => 'Den granulære adgang redigeres fra', 'en' => 'Granular access is edited from'],
    'users_settings_link'    => ['ro' => 'Setări → Utilizatori', 'da' => 'Indstillinger → Brugere', 'en' => 'Settings → Users'],
    'login_email_will_sync'  => ['ro' => 'Emailul de login va fi sincronizat automat cu emailul de asociație (', 'da' => 'Login-emailen bliver automatisk synkroniseret med foreningsmailen (', 'en' => 'The login email will be automatically synced with the association email ('],
    'next_time_you_save'     => ['ro' => ') data viitoare când salvezi profilul de mai sus.', 'da' => ') næste gang du gemmer profilen ovenfor.', 'en' => ') the next time you save the profile above.'],
    'reset_pwd_resend_confirm'=> ['ro' => 'Resetezi parola și retrimiți emailul de welcome?', 'da' => 'Nulstil adgangskode og gensend velkomstemail?', 'en' => 'Reset the password and resend the welcome email?'],
    'reset_pwd_resend_btn'   => ['ro' => 'Resetează parola + retrimite welcome', 'da' => 'Nulstil adgangskode + gensend velkomst', 'en' => 'Reset password + resend welcome'],
    'grant_role_intro'       => ['ro' => 'Îl poți desemna într-un rol în panoul admin — președinte, vicepreședinte, trezorier, revizor sau consilier. Alege mai întâi poziția; accesul granular (bifele) apare doar pentru consilier și revizor — celelalte poziții au acces fix, definit de rol. Dacă poziția e deja ocupată de un cont activ, acesta va fi înlocuit automat — contul lui va fi șters — la desemnare.',
                                  'da' => 'Du kan udpege medlemmet til en rolle i adminpanelet — formand, næstformand, kasserer, revisor eller bestyrelsesmedlem. Vælg først positionen; den granulære adgang (afkrydsning) vises kun for bestyrelsesmedlem og revisor — de andre positioner har fast adgang, defineret af rollen. Hvis positionen allerede er besat af en aktiv konto, bliver den automatisk erstattet — kontoen slettes — ved udpegning.',
                                  'en' => 'You can assign the member a role in the admin panel — president, vice-president, treasurer, auditor, or councillor. Choose the position first; granular access (checkboxes) only appears for councillor and auditor — the other positions have fixed, role-defined access. If the position is already held by an active account, it will be automatically replaced — that account will be deleted — on assignment.'],
    'position_field'         => ['ro' => 'Poziție', 'da' => 'Position', 'en' => 'Position'],
    'custom_role_field'      => ['ro' => 'Rol personalizat', 'da' => 'Tilpasset rolle', 'en' => 'Custom role'],
    'custom_role_ph'         => ['ro' => 'ex: Responsabil social media', 'da' => 'fx: Ansvarlig for sociale medier', 'en' => 'e.g. Social media lead'],
    'login_email_label'      => ['ro' => 'Email de login:', 'da' => 'Login-email:', 'en' => 'Login email:'],
    'association_email_paren'=> ['ro' => '(emailul de asociație, completat mai sus)', 'da' => '(foreningsmailen, udfyldt ovenfor)', 'en' => '(the association email, filled in above)'],
    'personal_email_paren'   => ['ro' => '(emailul personal — completează un email de asociație mai sus ca să-l folosească pe acela)', 'da' => '(den personlige email — udfyld en foreningsmail ovenfor for at bruge den i stedet)', 'en' => "(the personal email — fill in an association email above to use that instead)"],
    'access_checklist_label' => ['ro' => 'Acces — bifează ce poate face', 'da' => 'Adgang — afkryds hvad personen kan gøre', 'en' => 'Access — check what they can do'],
    'send_welcome_checkbox'  => ['ro' => 'Trimite email de welcome cu datele de prim login', 'da' => 'Send velkomstemail med data til første login', 'en' => 'Send a welcome email with the first-login details'],
    'designate_btn'          => ['ro' => 'Desemnează', 'da' => 'Udpeg', 'en' => 'Assign'],
    'link_existing_account_label'=> ['ro' => 'Conectează un cont existent', 'da' => 'Forbind en eksisterende konto', 'en' => 'Link an existing account'],
    'link_existing_intro'    => ['ro' => 'Există conturi în panou care nu sunt legate de niciun profil de membru — de exemplu unul adăugat manual din Setări → Utilizatori, cu parolă deja setată. Le poți conecta la profilul acesta fără să schimbi parola contului.',
                                  'da' => 'Der findes konti i panelet, som ikke er forbundet til nogen medlemsprofil — fx en, der er tilføjet manuelt fra Indstillinger → Brugere, med adgangskode allerede sat. Du kan forbinde dem til denne profil uden at ændre kontoens adgangskode.',
                                  'en' => "There are accounts in the panel not linked to any member profile — for example one added manually from Settings → Users, with a password already set. You can link them to this profile without changing the account's password."],
    'account_field'          => ['ro' => 'Cont', 'da' => 'Konto', 'en' => 'Account'],
    'delete_connected_account_prefix'=> ['ro' => 'Șterge contul deja conectat (', 'da' => 'Slet den allerede forbundne konto (', 'en' => 'Delete the already-linked account ('],
    'connect_btn'            => ['ro' => 'Conectează', 'da' => 'Forbind', 'en' => 'Link'],
    'contributing_projects_section'=> ['ro' => 'Proiecte la care contribuie', 'da' => 'Projekter vedkommende bidrager til', 'en' => 'Projects contributed to'],
    'contribution_status_active' => ['ro' => 'Activ', 'da' => 'Aktiv', 'en' => 'Active'],
    'contribution_status_done'   => ['ro' => 'Finalizat', 'da' => 'Afsluttet', 'en' => 'Completed'],
    'remove_contribution_confirm'=> ['ro' => 'Elimini această contribuție?', 'da' => 'Fjern dette bidrag?', 'en' => 'Remove this contribution?'],
    'remove_btn'              => ['ro' => 'Elimină', 'da' => 'Fjern', 'en' => 'Remove'],
    'project_field'           => ['ro' => 'Proiect', 'da' => 'Projekt', 'en' => 'Project'],
    'add_short'                => ['ro' => 'Adaugă', 'da' => 'Tilføj', 'en' => 'Add'],
    'all_projects_added'      => ['ro' => 'Toate proiectele existente sunt deja adăugate.', 'da' => 'Alle eksisterende projekter er allerede tilføjet.', 'en' => 'All existing projects have already been added.'],

    // ── Export membri — sortare + format ──
    'sort_by_label'          => ['ro' => 'Sortează după', 'da' => 'Sortér efter', 'en' => 'Sort by'],
    'sort_dir_asc'           => ['ro' => 'Crescător', 'da' => 'Stigende', 'en' => 'Ascending'],
    'sort_dir_desc'          => ['ro' => 'Descrescător', 'da' => 'Faldende', 'en' => 'Descending'],
    'export_format_label'    => ['ro' => 'Format export', 'da' => 'Eksportformat', 'en' => 'Export format'],
    'format_pdf'             => ['ro' => 'PDF (document tipăribil)', 'da' => 'PDF (udskriftsvenligt dokument)', 'en' => 'PDF (printable document)'],
    'format_csv'             => ['ro' => 'Excel (CSV)', 'da' => 'Excel (CSV)', 'en' => 'Excel (CSV)'],

    // ── Propuneri ──
    'proposal_updated'       => ['ro' => 'Propunerea a fost actualizată.', 'da' => 'Forslaget er opdateret.', 'en' => 'The proposal was updated.'],
    'proposal_deleted'       => ['ro' => 'Propunerea a fost ștearsă.', 'da' => 'Forslaget er slettet.', 'en' => 'The proposal was deleted.'],
    'meeting_created_prefix' => ['ro' => 'Adunare creată: ', 'da' => 'Møde oprettet: ', 'en' => 'Meeting created: '],
    'updated_short'          => ['ro' => 'Actualizat.', 'da' => 'Opdateret.', 'en' => 'Updated.'],
    'nav_topics_h1'          => ['ro' => 'Ședințe', 'da' => 'Møder', 'en' => 'Meetings'],
    'meeting_open_badge'     => ['ro' => 'Deschis', 'da' => 'Åben', 'en' => 'Open'],
    'meeting_closed_badge'   => ['ro' => 'Închis', 'da' => 'Lukket', 'en' => 'Closed'],
    'new_proposal'           => ['ro' => 'Propunere nouă', 'da' => 'Nyt forslag', 'en' => 'New proposal'],
    'export_ai'              => ['ro' => 'Export AI', 'da' => 'AI-eksport', 'en' => 'AI export'],
    'meetings_label'         => ['ro' => 'Adunări', 'da' => 'Møder', 'en' => 'Meetings'],
    'closed_paren'           => ['ro' => '(închis)', 'da' => '(lukket)', 'en' => '(closed)'],
    'hidden_paren'           => ['ro' => '(ascuns)', 'da' => '(skjult)', 'en' => '(hidden)'],
    'meeting_settings_label' => ['ro' => 'Setări adunare', 'da' => 'Mødeindstillinger', 'en' => 'Meeting settings'],
    'close_btn'              => ['ro' => 'Închide', 'da' => 'Luk', 'en' => 'Close'],
    'open_btn'                => ['ro' => 'Deschide', 'da' => 'Åbn', 'en' => 'Open'],
    'hide_btn'                => ['ro' => 'Ascunde', 'da' => 'Skjul', 'en' => 'Hide'],
    'show_btn'                => ['ro' => 'Afișează', 'da' => 'Vis', 'en' => 'Show'],
    'new_meeting_btn'        => ['ro' => 'Adunare nouă', 'da' => 'Nyt møde', 'en' => 'New meeting'],
    'meeting_title_field'    => ['ro' => 'Titlu', 'da' => 'Titel', 'en' => 'Title'],
    'meeting_title_ph'       => ['ro' => 'ex: GF 2027', 'da' => 'fx: GF 2027', 'en' => 'e.g. AGM 2027'],
    'meeting_type_field'     => ['ro' => 'Tip', 'da' => 'Type', 'en' => 'Type'],
    'meeting_type_general'   => ['ro' => 'Generalforsamling', 'da' => 'Generalforsamling', 'en' => 'Generalforsamling'],
    'meeting_type_extra'     => ['ro' => 'Adunare extraordinară', 'da' => 'Ekstraordinær generalforsamling', 'en' => 'Extraordinary meeting'],
    'meeting_date_field'     => ['ro' => 'Data', 'da' => 'Dato', 'en' => 'Date'],
    'meeting_open_checkbox'  => ['ro' => 'Deschis pentru propuneri', 'da' => 'Åben for forslag', 'en' => 'Open for proposals'],
    'meeting_visible_checkbox'=> ['ro' => 'Vizibil pentru toți', 'da' => 'Synlig for alle', 'en' => 'Visible to everyone'],
    'create_btn'              => ['ro' => 'Creează', 'da' => 'Opret', 'en' => 'Create'],
    'no_proposals_yet'        => ['ro' => 'Nicio propunere încă pentru această adunare.', 'da' => 'Ingen forslag endnu til dette møde.', 'en' => 'No proposals yet for this meeting.'],
    'add_first_proposal'      => ['ro' => 'Adaugă prima propunere', 'da' => 'Tilføj det første forslag', 'en' => 'Add the first proposal'],
    'votes_label'             => ['ro' => 'voturi', 'da' => 'stemmer', 'en' => 'votes'],
    'you_badge'               => ['ro' => 'Tu', 'da' => 'Dig', 'en' => 'You'],
    'withdraw_vote'           => ['ro' => 'Retrage', 'da' => 'Træk tilbage', 'en' => 'Withdraw'],
    'support_vote'            => ['ro' => 'Susține', 'da' => 'Støt', 'en' => 'Support'],
    'delete_short_confirm'    => ['ro' => 'Ștergi?', 'da' => 'Slet?', 'en' => 'Delete?'],
    'cat_administrativ'       => ['ro' => 'Administrativ', 'da' => 'Administrativt', 'en' => 'Administrative'],
    'cat_proiecte'            => ['ro' => 'Proiecte', 'da' => 'Projekter', 'en' => 'Projects'],
    'cat_financiar'           => ['ro' => 'Financiar', 'da' => 'Økonomi', 'en' => 'Financial'],
    'cat_altul'               => ['ro' => 'Altul', 'da' => 'Andet', 'en' => 'Other'],
    'cannot_propose'          => ['ro' => 'Nu poți propune subiecte noi.', 'da' => 'Du kan ikke foreslå nye emner.', 'en' => "You can't propose new topics."],
    'no_open_meeting'         => ['ro' => 'Nicio adunare deschisă.', 'da' => 'Intet åbent møde.', 'en' => 'No open meeting.'],
    'cannot_edit_proposal'    => ['ro' => 'Nu poți edita această propunere.', 'da' => 'Du kan ikke redigere dette forslag.', 'en' => "You can't edit this proposal."],
    'title_required'          => ['ro' => 'Titlul e obligatoriu.', 'da' => 'Titel er påkrævet.', 'en' => 'Title is required.'],
    'proposal_added'          => ['ro' => 'Propunere adăugată.', 'da' => 'Forslag tilføjet.', 'en' => 'Proposal added.'],
    'proposal_updated_short'  => ['ro' => 'Propunere actualizată.', 'da' => 'Forslag opdateret.', 'en' => 'Proposal updated.'],
    'back_to_proposals'       => ['ro' => '← Înapoi la propuneri', 'da' => '← Tilbage til forslag', 'en' => '← Back to proposals'],
    'edit_proposal_h1'        => ['ro' => 'Editează propunerea', 'da' => 'Rediger forslaget', 'en' => 'Edit proposal'],
    'for_label'               => ['ro' => 'Pentru:', 'da' => 'Til:', 'en' => 'For:'],
    'proposed_topic_label'    => ['ro' => 'Topicul propus', 'da' => 'Det foreslåede emne', 'en' => 'The proposed topic'],
    'proposal_title_ph'       => ['ro' => 'Un titlu clar și concis', 'da' => 'En klar og kortfattet titel', 'en' => 'A clear, concise title'],
    'description_context_label'=> ['ro' => 'Descriere / context', 'da' => 'Beskrivelse / kontekst', 'en' => 'Description / context'],
    'proposal_description_ph' => ['ro' => 'De ce propui acest topic? Ce vrei să se discute sau decidă?', 'da' => 'Hvorfor foreslår du dette emne? Hvad vil du have drøftet eller besluttet?', 'en' => 'Why are you proposing this topic? What do you want discussed or decided?'],
    'add_proposal_btn'        => ['ro' => 'Adaugă propunerea', 'da' => 'Tilføj forslaget', 'en' => 'Add proposal'],
    'pos_presedinte'          => ['ro' => 'Președinte', 'da' => 'Formand', 'en' => 'President'],
    'pos_vicepresedinte'      => ['ro' => 'Vicepreședinte', 'da' => 'Næstformand', 'en' => 'Vice President'],
    'pos_trezorier'           => ['ro' => 'Trezorier', 'da' => 'Kasserer', 'en' => 'Treasurer'],
    'pos_consilier'           => ['ro' => 'Consilier', 'da' => 'Bestyrelsesmedlem', 'en' => 'Board member'],
    'export_md_date_label'    => ['ro' => 'Data', 'da' => 'Dato', 'en' => 'Date'],
    'export_md_location_label'=> ['ro' => 'Locație', 'da' => 'Sted', 'en' => 'Location'],
    'export_md_active_members'=> ['ro' => 'Membri activi', 'da' => 'Aktive medlemmer', 'en' => 'Active members'],
    'export_md_proposals'     => ['ro' => 'Propuneri', 'da' => 'Forslag', 'en' => 'Proposals'],
    'export_md_export_label'  => ['ro' => 'Export', 'da' => 'Eksport', 'en' => 'Export'],
    'export_md_ai_instructions_h'  => ['ro' => 'Instrucțiuni pentru AI', 'da' => 'Instruktioner til AI', 'en' => 'Instructions for AI'],
    'export_md_ai_intro'      => ['ro' => 'Ești asistentul pentru organizarea adunării generale a **Foreningen Front Door**, o asociație culturală romano-daneză din Danemarca.', 'da' => 'Du er assistenten for planlægningen af generalforsamlingen for **Foreningen Front Door**, en dansk-rumænsk kulturforening i Danmark.', 'en' => 'You are the assistant for organizing the general assembly of **Foreningen Front Door**, a Romanian-Danish cultural association in Denmark.'],
    'export_md_ai_based_on'   => ['ro' => 'Pe baza propunerilor de mai jos:', 'da' => 'På baggrund af forslagene nedenfor:', 'en' => 'Based on the proposals below:'],
    'export_md_ai_step1'      => ['ro' => '**Estimează timpul** pentru fiecare topic (minute), ținând cont de complexitate și voturi.', 'da' => '**Estimér tiden** for hvert emne (minutter), under hensyntagen til kompleksitet og stemmer.', 'en' => '**Estimate the time** for each topic (minutes), taking complexity and votes into account.'],
    'export_md_ai_step2'      => ['ro' => '**Propune un moderator** din lista membrilor, bazat pe relevanța rolului.', 'da' => '**Foreslå en mødeleder** fra medlemslisten, baseret på rollens relevans.', 'en' => '**Suggest a moderator** from the member list, based on role relevance.'],
    'export_md_ai_step3'      => ['ro' => '**Sugerează o ordine de zi** logică: administrativ → financiar → proiecte → altele.', 'da' => '**Foreslå en logisk dagsorden**: administrativt → økonomi → projekter → andet.', 'en' => '**Suggest a logical agenda order**: administrative → financial → projects → other.'],
    'export_md_ai_step4'      => ['ro' => '**Estimează durata totală** și sugerează o pauză dacă e cazul.', 'da' => '**Estimér den samlede varighed** og foreslå en pause, hvis relevant.', 'en' => '**Estimate the total duration** and suggest a break if needed.'],
    'export_md_ai_step5'      => ['ro' => '**Clasifică** fiecare topic: decizie formală / informare / dezbatere.', 'da' => '**Klassificér** hvert emne: formel beslutning / orientering / debat.', 'en' => '**Classify** each topic: formal decision / information / debate.'],
    'export_md_board_members_h'=> ['ro' => 'Membrii bestyrelse', 'da' => 'Bestyrelsesmedlemmer', 'en' => 'Board members'],
    'export_md_proposals_by_votes_h'=> ['ro' => 'Propuneri (ordonate după voturi)', 'da' => 'Forslag (sorteret efter stemmer)', 'en' => 'Proposals (sorted by votes)'],
    'export_md_votes_row'     => ['ro' => 'Voturi', 'da' => 'Stemmer', 'en' => 'Votes'],
    'export_md_category_row'  => ['ro' => 'Categorie', 'da' => 'Kategori', 'en' => 'Category'],
    'export_md_proposed_by_row'=> ['ro' => 'Propus de', 'da' => 'Foreslået af', 'en' => 'Proposed by'],
    'export_md_time_est_row'  => ['ro' => 'Timp estimat', 'da' => 'Estimeret tid', 'en' => 'Estimated time'],
    'export_md_moderator_row' => ['ro' => 'Moderator propus', 'da' => 'Foreslået mødeleder', 'en' => 'Suggested moderator'],
    'export_md_type_row'      => ['ro' => 'Tip', 'da' => 'Type', 'en' => 'Type'],
    'export_md_type_ai_hint'  => ['ro' => '_AI: decizie / informare / dezbatere_', 'da' => '_AI: beslutning / orientering / debat_', 'en' => '_AI: decision / information / debate_'],
    'export_md_description_label'=> ['ro' => 'Descriere', 'da' => 'Beskrivelse', 'en' => 'Description'],
    'export_md_agenda_h'      => ['ro' => 'Ordine de zi propusă (de completat de AI)', 'da' => 'Foreslået dagsorden (udfyldes af AI)', 'en' => 'Proposed agenda (to be filled in by AI)'],
    'export_md_agenda_col_nr' => ['ro' => 'Nr.', 'da' => 'Nr.', 'en' => 'No.'],
    'export_md_agenda_col_topic'=> ['ro' => 'Topic', 'da' => 'Emne', 'en' => 'Topic'],
    'export_md_agenda_col_time'=> ['ro' => 'Timp (min)', 'da' => 'Tid (min)', 'en' => 'Time (min)'],
    'export_md_agenda_col_moderator'=> ['ro' => 'Moderator', 'da' => 'Mødeleder', 'en' => 'Moderator'],
    'export_md_agenda_col_type'=> ['ro' => 'Tip', 'da' => 'Type', 'en' => 'Type'],
    'export_md_total_duration'=> ['ro' => 'Durată totală estimată', 'da' => 'Estimeret samlet varighed', 'en' => 'Total estimated duration'],
    'export_md_generated_by'  => ['ro' => 'Generat de', 'da' => 'Genereret af', 'en' => 'Generated by'],
    'login_portal_admin'      => ['ro' => 'Portal Admin', 'da' => 'Admin-portal', 'en' => 'Admin Portal'],
    'login_hero_title'        => ['ro' => 'Bine ai venit înapoi', 'da' => 'Velkommen tilbage', 'en' => 'Welcome back'],
    'login_hero_sub'          => ['ro' => 'Autentifică-te pentru a gestiona evenimentele, membrii și proiectele Foreningen Front Door.', 'da' => 'Log ind for at administrere Foreningen Front Doors arrangementer, medlemmer og projekter.', 'en' => 'Sign in to manage Foreningen Front Door’s events, members and projects.'],
    'field_email'             => ['ro' => 'Email', 'da' => 'Email', 'en' => 'Email'],
    'field_password'          => ['ro' => 'Parolă', 'da' => 'Adgangskode', 'en' => 'Password'],
    'login_btn'                => ['ro' => 'Intră', 'da' => 'Log ind', 'en' => 'Sign in'],
    'err_wrong_credentials'   => ['ro' => 'Email sau parolă incorectă.', 'da' => 'Forkert email eller adgangskode.', 'en' => 'Incorrect email or password.'],
    'err_session_expired'     => ['ro' => 'Sesiunea a expirat.', 'da' => 'Sessionen er udløbet.', 'en' => 'Your session has expired.'],
    'back_to_site'            => ['ro' => '← Înapoi la site', 'da' => '← Tilbage til siden', 'en' => '← Back to site'],
    'no_perm_manage_documents'=> ['ro' => 'Nu ai permisiunea de a gestiona documentele.', 'da' => 'Du har ikke tilladelse til at administrere dokumenter.', 'en' => "You don't have permission to manage documents."],
    'title_ro_required'      => ['ro' => 'Titlu RO obligatoriu.', 'da' => 'RO-titel er påkrævet.', 'en' => 'RO title is required.'],
    'title_da_required'      => ['ro' => 'Titlu DA obligatoriu.', 'da' => 'DA-titel er påkrævet.', 'en' => 'DA title is required.'],
    'upload_error'            => ['ro' => 'Eroare upload.', 'da' => 'Uploadfejl.', 'en' => 'Upload error.'],
    'file_too_large_20mb'    => ['ro' => 'Fișierul depășește 20MB.', 'da' => 'Filen overstiger 20MB.', 'en' => 'File exceeds 20MB.'],
    'pdf_only_error'          => ['ro' => 'Doar fișiere PDF.', 'da' => 'Kun PDF-filer.', 'en' => 'PDF files only.'],
    'file_save_failed'        => ['ro' => 'Nu s-a putut salva fișierul.', 'da' => 'Filen kunne ikke gemmes.', 'en' => 'The file could not be saved.'],
    'pdf_file_required'      => ['ro' => 'Fișier PDF obligatoriu.', 'da' => 'PDF-fil er påkrævet.', 'en' => 'A PDF file is required.'],
    'document_added'          => ['ro' => 'Document adăugat.', 'da' => 'Dokument tilføjet.', 'en' => 'Document added.'],
    'visibility_updated'      => ['ro' => 'Vizibilitate actualizată.', 'da' => 'Synlighed opdateret.', 'en' => 'Visibility updated.'],
    'document_deleted'        => ['ro' => 'Document șters.', 'da' => 'Dokument slettet.', 'en' => 'Document deleted.'],
    'nav_documents_h1'        => ['ro' => 'Documente transparență', 'da' => 'Gennemsigtighedsdokumenter', 'en' => 'Transparency documents'],
    'view_page_btn'           => ['ro' => 'Vezi pagina →', 'da' => 'Se siden →', 'en' => 'View page →'],
    'new_document_label'      => ['ro' => 'Document nou', 'da' => 'Nyt dokument', 'en' => 'New document'],
    'title_ro_label'          => ['ro' => 'Titlu în română *', 'da' => 'Titel på rumænsk *', 'en' => 'Title in Romanian *'],
    'title_da_label'          => ['ro' => 'Titlu în daneză *', 'da' => 'Titel på dansk *', 'en' => 'Title in Danish *'],
    'doc_type_label'          => ['ro' => 'Tip document', 'da' => 'Dokumenttype', 'en' => 'Document type'],
    'meeting_date_field_label'=> ['ro' => 'Data ședinței', 'da' => 'Mødedato', 'en' => 'Meeting date'],
    'sort_order_label'        => ['ro' => 'Ordine', 'da' => 'Rækkefølge', 'en' => 'Order'],
    'pdf_file_label'          => ['ro' => 'Fișier PDF *', 'da' => 'PDF-fil *', 'en' => 'PDF file *'],
    'pdf_file_hint'           => ['ro' => 'Maxim 20MB, doar PDF.', 'da' => 'Maks. 20MB, kun PDF.', 'en' => 'Max 20MB, PDF only.'],
    'visible_public_checkbox' => ['ro' => 'Vizibil public pe pagina de transparență', 'da' => 'Synlig offentligt på gennemsigtighedssiden', 'en' => 'Publicly visible on the transparency page'],
    'upload_document_btn'     => ['ro' => 'Încarcă documentul', 'da' => 'Upload dokumentet', 'en' => 'Upload document'],
    'existing_documents_label'=> ['ro' => 'Documente existente', 'da' => 'Eksisterende dokumenter', 'en' => 'Existing documents'],
    'no_documents_yet'        => ['ro' => 'Niciun document încărcat încă.', 'da' => 'Ingen dokumenter uploadet endnu.', 'en' => 'No documents uploaded yet.'],
    'th_size'                 => ['ro' => 'Mărime', 'da' => 'Størrelse', 'en' => 'Size'],
    'th_visible'              => ['ro' => 'Vizibil', 'da' => 'Synlig', 'en' => 'Visible'],
    'public_badge'            => ['ro' => 'Public', 'da' => 'Offentlig', 'en' => 'Public'],
    'hidden_badge'            => ['ro' => 'Ascuns', 'da' => 'Skjult', 'en' => 'Hidden'],
    'publish_btn'             => ['ro' => 'Publică', 'da' => 'Offentliggør', 'en' => 'Publish'],
    'delete_document_confirm' => ['ro' => 'Ștergi documentul și fișierul PDF?', 'da' => 'Slet dokumentet og PDF-filen?', 'en' => 'Delete the document and its PDF file?'],
    'doc_type_referat'        => ['ro' => 'Referat', 'da' => 'Referat', 'en' => 'Minutes'],
    'doc_type_raport'         => ['ro' => 'Raport anual', 'da' => 'Årsrapport', 'en' => 'Annual report'],
    'doc_type_statut'         => ['ro' => 'Statut', 'da' => 'Vedtægter', 'en' => 'Statute'],
    'doc_type_altele'         => ['ro' => 'Altele', 'da' => 'Andet', 'en' => 'Other'],
    'err_current_pwd_wrong'   => ['ro' => 'Parola curentă e incorectă.', 'da' => 'Den nuværende adgangskode er forkert.', 'en' => 'The current password is incorrect.'],
    'err_new_pwd_min_len'     => ['ro' => 'Parola nouă — minim 10 caractere.', 'da' => 'Ny adgangskode — mindst 10 tegn.', 'en' => 'New password — at least 10 characters.'],
    'err_pwd_mismatch'        => ['ro' => 'Parolele nu coincid.', 'da' => 'Adgangskoderne stemmer ikke overens.', 'en' => 'The passwords do not match.'],
    'err_new_pwd_same_as_current'=> ['ro' => 'Parola nouă trebuie să difere de cea actuală.', 'da' => 'Den nye adgangskode skal være forskellig fra den nuværende.', 'en' => 'The new password must be different from the current one.'],
    'pwd_changed_ok'          => ['ro' => 'Parola a fost schimbată.', 'da' => 'Adgangskoden er blevet ændret.', 'en' => 'Password changed successfully.'],
    'change_pwd_h_sub'        => ['ro' => 'Schimbă parola', 'da' => 'Skift adgangskode', 'en' => 'Change password'],
    'change_pwd_notice_prefix'=> ['ro' => 'Bun venit, ', 'da' => 'Velkommen, ', 'en' => 'Welcome, '],
    'change_pwd_notice_suffix'=> ['ro' => '. Setează o parolă personală înainte de a continua.', 'da' => '. Angiv en personlig adgangskode, før du fortsætter.', 'en' => '. Set a personal password before continuing.'],
    'current_password_label'  => ['ro' => 'Parola actuală', 'da' => 'Nuværende adgangskode', 'en' => 'Current password'],
    'new_password_label'      => ['ro' => 'Parola nouă', 'da' => 'Ny adgangskode', 'en' => 'New password'],
    'new_password_hint'       => ['ro' => 'Minim 10 caractere.', 'da' => 'Mindst 10 tegn.', 'en' => 'At least 10 characters.'],
    'confirm_new_password_label'=> ['ro' => 'Confirmă parola nouă', 'da' => 'Bekræft ny adgangskode', 'en' => 'Confirm new password'],
    'set_password_btn'        => ['ro' => 'Setează parola →', 'da' => 'Angiv adgangskode →', 'en' => 'Set password →'],
    'source_data_label'      => ['ro' => 'Sursa datelor:', 'da' => 'Datakilde:', 'en' => 'Data source:'],
    'source_data_suffix'     => ['ro' => ' — se editează acolo, aici e doar afișare.', 'da' => ' — redigeres der, her vises det kun.', 'en' => ' — edited there; this is a read-only view.'],
    'gs_error_intro'          => ['ro' => 'Nu s-au putut încărca datele din Google Sheets.', 'da' => 'Data kunne ikke indlæses fra Google Sheets.', 'en' => 'Could not load the data from Google Sheets.'],
    'gs_error_check_config'  => ['ro' => 'Verifică în <code>config.php</code> că <code>GOOGLE_SA_CLIENT_EMAIL</code>, <code>GOOGLE_SA_PRIVATE_KEY</code> și <code>GOOGLE_SHEETS_SPREADSHEET_ID</code> sunt completate, și că documentul e partajat (Share) cu adresa contului de service ca Editor sau Viewer.', 'da' => 'Tjek i <code>config.php</code>, at <code>GOOGLE_SA_CLIENT_EMAIL</code>, <code>GOOGLE_SA_PRIVATE_KEY</code> og <code>GOOGLE_SHEETS_SPREADSHEET_ID</code> er udfyldt, og at dokumentet er delt (Share) med servicekontoens adresse som Editor eller Viewer.', 'en' => 'Check in <code>config.php</code> that <code>GOOGLE_SA_CLIENT_EMAIL</code>, <code>GOOGLE_SA_PRIVATE_KEY</code> and <code>GOOGLE_SHEETS_SPREADSHEET_ID</code> are filled in, and that the document is shared (Share) with the service account address as Editor or Viewer.'],
    'gs_empty_intro'          => ['ro' => 'Conexiunea cu Google Sheets funcționează, dar foaia de calcul e încă goală.', 'da' => 'Forbindelsen til Google Sheets virker, men regnearket er stadig tomt.', 'en' => 'The connection to Google Sheets works, but the spreadsheet is still empty.'],
    'gs_empty_hint'            => ['ro' => 'Adaugă rânduri în tab-urile <strong>Bilag</strong>, <strong>Donationer</strong>, <strong>Projekter</strong> și <strong>Kontingent</strong> — apar automat aici la următoarea încărcare a paginii.', 'da' => 'Tilføj rækker i fanerne <strong>Bilag</strong>, <strong>Donationer</strong>, <strong>Projekter</strong> og <strong>Kontingent</strong> — de vises automatisk her ved næste sideindlæsning.', 'en' => 'Add rows in the <strong>Bilag</strong>, <strong>Donationer</strong>, <strong>Projekter</strong> and <strong>Kontingent</strong> tabs — they show up here automatically on the next page load.'],
    'total_income_label'      => ['ro' => 'Venituri totale', 'da' => 'Samlede indtægter', 'en' => 'Total income'],
    'total_expenses_label'    => ['ro' => 'Cheltuieli totale', 'da' => 'Samlede udgifter', 'en' => 'Total expenses'],
    'balance_label'            => ['ro' => 'Sold', 'da' => 'Saldo', 'en' => 'Balance'],
    'by_category_h'            => ['ro' => 'Pe categorii', 'da' => 'Efter kategori', 'en' => 'By category'],
    'rk_income_label'          => ['ro' => 'Venituri', 'da' => 'Indtægter', 'en' => 'Income'],
    'rk_expenses_label'        => ['ro' => 'Cheltuieli', 'da' => 'Udgifter', 'en' => 'Expenses'],
    'projects_budget_h'        => ['ro' => 'Proiecte — buget & fundraising', 'da' => 'Projekter — budget & fundraising', 'en' => 'Projects — budget & fundraising'],
    'raised_of'                 => ['ro' => 'strânși din', 'da' => 'indsamlet ud af', 'en' => 'raised out of'],
    'spent_suffix'             => ['ro' => ' cheltuiți', 'da' => ' brugt', 'en' => ' spent'],
    'donations_h'               => ['ro' => 'Donații & fundraising', 'da' => 'Donationer & fundraising', 'en' => 'Donations & fundraising'],
    'donations_summary'        => ['ro' => 'Total: %s din %d donații', 'da' => 'I alt: %s ud af %d donationer', 'en' => 'Total: %s from %d donations'],
    'kontingent_summary'      => ['ro' => 'Total: %s din %d plăți', 'da' => 'I alt: %s ud af %d betalinger', 'en' => 'Total: %s from %d payments'],
    'bilag_h'                   => ['ro' => 'Bilag — toate tranzacțiile', 'da' => 'Bilag — alle transaktioner', 'en' => 'Bilag — all transactions'],
    'bilag_rows_count'         => ['ro' => '%d rânduri', 'da' => '%d rækker', 'en' => '%d rows'],
    'th_donor'                  => ['ro' => 'Donator', 'da' => 'Donor', 'en' => 'Donor'],
    'th_amount'                 => ['ro' => 'Sumă', 'da' => 'Beløb', 'en' => 'Amount'],
    'th_project_purpose'      => ['ro' => 'Proiect/scop', 'da' => 'Projekt/formål', 'en' => 'Project/purpose'],
    'th_receipt'                => ['ro' => 'Chitanță', 'da' => 'Kvittering', 'en' => 'Receipt'],
    'th_member'                  => ['ro' => 'Membru', 'da' => 'Medlem', 'en' => 'Member'],
    'th_payment_date'          => ['ro' => 'Dată plată', 'da' => 'Betalingsdato', 'en' => 'Payment date'],
    'th_valid_year'            => ['ro' => 'An valabil', 'da' => 'Gyldigt år', 'en' => 'Valid year'],
    'th_nr'                      => ['ro' => 'Nr.', 'da' => 'Nr.', 'en' => 'No.'],
    'th_description'          => ['ro' => 'Descriere', 'da' => 'Beskrivelse', 'en' => 'Description'],
    'th_project'                => ['ro' => 'Proiect', 'da' => 'Projekt', 'en' => 'Project'],
    'th_income'                 => ['ro' => 'Venit', 'da' => 'Indtægt', 'en' => 'Income'],
    'th_expense'                => ['ro' => 'Cheltuială', 'da' => 'Udgift', 'en' => 'Expense'],
    'social_gen_h'              => ['ro' => 'Generator Social Media', 'da' => 'Social Media-generator', 'en' => 'Social Media Generator'],
    'social_gen_sub'            => ['ro' => 'Selectează un eveniment sau proiect, alege platforma și copiază textul.', 'da' => 'Vælg et arrangement eller projekt, vælg platform og kopiér teksten.', 'en' => 'Select an event or project, choose the platform and copy the text.'],
    'no_upcoming_events'        => ['ro' => 'Niciun eveniment viitor activ.', 'da' => 'Ingen aktive kommende arrangementer.', 'en' => 'No active upcoming events.'],
    'no_active_projects_social' => ['ro' => 'Niciun proiect activ.', 'da' => 'Ingen aktive projekter.', 'en' => 'No active projects.'],
    'generated_content_label'   => ['ro' => 'Conținut generat', 'da' => 'Genereret indhold', 'en' => 'Generated content'],
    'opt_emoji'                  => ['ro' => 'Emoji', 'da' => 'Emoji', 'en' => 'Emoji'],
    'opt_hashtags'               => ['ro' => 'Hashtag-uri', 'da' => 'Hashtags', 'en' => 'Hashtags'],
    'opt_signup_link'            => ['ro' => 'Link înscriere', 'da' => 'Tilmeldingslink', 'en' => 'Signup link'],
    'fb_hint'                    => ['ro' => 'Text lung ok, link-uri funcționează. Imaginea din cover eveniment.', 'da' => 'Lang tekst er ok, links virker. Billedet fra begivenhedens cover.', 'en' => 'Long text is fine, links work. Image from the event cover.'],
    'ig_hint'                    => ['ro' => 'Caption scurt vizibil (sub 150 car.), hashtag-uri la final, link în bio.', 'da' => 'Kort synlig caption (under 150 tegn), hashtags til sidst, link i bio.', 'en' => 'Short visible caption (under 150 chars), hashtags at the end, link in bio.'],
    'tt_hint'                    => ['ro' => 'Max 150 caractere vizibile. Hook în prima linie.', 'da' => 'Maks. 150 synlige tegn. Hook i første linje.', 'en' => 'Max 150 visible characters. Hook in the first line.'],
    'copy_for_prefix'            => ['ro' => 'Copiază pentru ', 'da' => 'Kopiér til ', 'en' => 'Copy for '],
    'characters_word'            => ['ro' => 'caractere', 'da' => 'tegn', 'en' => 'characters'],
    'copied_label'                => ['ro' => 'Copiat!', 'da' => 'Kopieret!', 'en' => 'Copied!'],
    'cat_social'                  => ['ro' => 'Social', 'da' => 'Socialt', 'en' => 'Social'],
    'nav_dashboard'               => ['ro' => 'Acasă', 'da' => 'Hjem', 'en' => 'Home'],
    'dashboard_welcome_prefix'    => ['ro' => 'Bine ai venit, ', 'da' => 'Velkommen, ', 'en' => 'Welcome, '],
    'dashboard_subtitle'          => ['ro' => 'Alege o secțiune pentru a continua.', 'da' => 'Vælg et område for at fortsætte.', 'en' => 'Choose a section to continue.'],
    'dash_events_desc'            => ['ro' => 'Creează și administrează evenimentele publice.', 'da' => 'Opret og administrer offentlige arrangementer.', 'en' => 'Create and manage public events.'],
    'dash_projects_desc'          => ['ro' => 'Urmărește proiectele și progresul lor.', 'da' => 'Følg projekterne og deres fremgang.', 'en' => 'Track projects and their progress.'],
    'dash_members_desc'           => ['ro' => 'Gestionează cererile și profilurile membrilor.', 'da' => 'Administrer medlemsanmodninger og -profiler.', 'en' => 'Manage membership requests and profiles.'],
    'dash_topics_desc'            => ['ro' => 'Propuneri și adunări generale.', 'da' => 'Forslag og generalforsamlinger.', 'en' => 'Proposals and general assemblies.'],
    'dash_documents_desc'         => ['ro' => 'Documente de transparență publicate.', 'da' => 'Offentliggjorte gennemsigtighedsdokumenter.', 'en' => 'Published transparency documents.'],
    'dash_regnskab_desc'          => ['ro' => 'Situația financiară a asociației.', 'da' => 'Foreningens økonomiske situation.', 'en' => "The association's financial overview."],
    'dash_settings_desc'          => ['ro' => 'Cont, limbă și permisiuni.', 'da' => 'Konto, sprog og tilladelser.', 'en' => 'Account, language and permissions.'],

    // ── bucket ședință / vot live ──
    'manage_meeting_btn'          => ['ro' => 'Gestionează ședința', 'da' => 'Administrer mødet', 'en' => 'Manage meeting'],
    'bucket_h1'                   => ['ro' => 'Ședința', 'da' => 'Mødet', 'en' => 'Meeting'],
    'bucket_back_to_topics'       => ['ro' => '← Înapoi la propuneri', 'da' => '← Tilbage til forslag', 'en' => '← Back to proposals'],
    'bucket_logistics_h'          => ['ro' => 'Loc, dată, oră', 'da' => 'Sted, dato, tid', 'en' => 'Place, date, time'],
    'bucket_location_label'       => ['ro' => 'Loc', 'da' => 'Sted', 'en' => 'Location'],
    'bucket_date_label'           => ['ro' => 'Dată', 'da' => 'Dato', 'en' => 'Date'],
    'bucket_time_label'           => ['ro' => 'Oră start', 'da' => 'Starttid', 'en' => 'Start time'],
    'online_link_label'           => ['ro' => 'Link online (dacă e cazul)', 'da' => 'Onlinelink (hvis relevant)', 'en' => 'Online link (if applicable)'],
    'proposals_email_label'       => ['ro' => 'Email pentru propuneri', 'da' => 'Email til forslag', 'en' => 'Proposals email'],
    'proposal_deadline_label'     => ['ro' => 'Termen limită propuneri', 'da' => 'Frist for forslag', 'en' => 'Proposal deadline'],
    'save_logistics_btn'          => ['ro' => 'Salvează', 'da' => 'Gem', 'en' => 'Save'],
    'date_confirmed_checkbox'     => ['ro' => 'Data e confirmată cu bestyrelsen', 'da' => 'Datoen er bekræftet med bestyrelsen', 'en' => 'Date confirmed with the board'],
    'date_not_confirmed_notice'   => ['ro' => 'Data nu e încă confirmată — convocarea nu poate fi trimisă până nu bifezi confirmarea.', 'da' => 'Datoen er endnu ikke bekræftet — indkaldelsen kan ikke sendes, før du markerer den som bekræftet.', 'en' => "The date isn't confirmed yet — the convocation can't be sent until you check the confirmation box."],
    'logistics_changed_after_send_warn' => ['ro' => 'Ai schimbat detaliile după ce convocarea a fost deja trimisă. Membrii au datele vechi — trimite un email de corecție sau anunța-i din nou.', 'da' => 'Du har ændret detaljerne, efter at indkaldelsen allerede er sendt. Medlemmerne har de gamle oplysninger — send en rettelse eller informer dem igen.', 'en' => "You changed the details after the convocation was already sent. Members have the old information — send a correction or re-announce."],
    'bucket_topics_h'             => ['ro' => 'Subiecte de votat', 'da' => 'Emner til afstemning', 'en' => 'Topics to vote'],
    'bucket_topics_sub'           => ['ro' => 'Bifează propunerile care intră în buchetul acestei ședințe. Ordinea și estimarea de timp se pot ajusta mai jos.', 'da' => 'Marker de forslag, der skal indgå i mødets emnepulje. Rækkefølge og tidsestimat kan justeres nedenfor.', 'en' => "Check the proposals that go into this meeting's bucket. Order and time estimate can be adjusted below."],
    'in_bucket_checkbox'          => ['ro' => 'În bucket', 'da' => 'I puljen', 'en' => 'In bucket'],
    'majority_type_label'         => ['ro' => 'Tip majoritate', 'da' => 'Flertalstype', 'en' => 'Majority type'],
    'majority_simple'             => ['ro' => 'Majoritate simplă', 'da' => 'Simpelt flertal', 'en' => 'Simple majority'],
    'majority_simple_short'       => ['ro' => 'simplă', 'da' => 'simpelt', 'en' => 'simple'],
    'majority_2_3'                => ['ro' => 'Modificare statut (2/3)', 'da' => 'Vedtægtsændring (2/3)', 'en' => 'Bylaw amendment (2/3)'],
    'majority_2_3_short'          => ['ro' => '2/3', 'da' => '2/3', 'en' => '2/3'],
    'est_minutes_label'           => ['ro' => 'Minute est.', 'da' => 'Est. minutter', 'en' => 'Est. minutes'],
    'moderator_label'             => ['ro' => 'Moderator', 'da' => 'Ordstyrer', 'en' => 'Moderator'],
    'moderator_none'              => ['ro' => '— fără —', 'da' => '— ingen —', 'en' => '— none —'],
    'move_up_btn'                 => ['ro' => '↑', 'da' => '↑', 'en' => '↑'],
    'move_down_btn'                => ['ro' => '↓', 'da' => '↓', 'en' => '↓'],
    'agenda_locked_notice'        => ['ro' => 'Agenda e blocată (versiunea finală a fost trimisă). Deblochează pentru a mai edita bucket-ul.', 'da' => 'Dagsordenen er låst (den endelige version er sendt). Lås op for at redigere puljen igen.', 'en' => 'The agenda is locked (the final version was sent). Unlock to edit the bucket again.'],
    'unlock_agenda_btn'           => ['ro' => 'Deblochează agenda', 'da' => 'Lås dagsordenen op', 'en' => 'Unlock agenda'],
    'no_proposals_for_bucket'     => ['ro' => 'Nu există încă propuneri pentru această ședință.', 'da' => 'Der er endnu ingen forslag til dette møde.', 'en' => 'There are no proposals yet for this meeting.'],
    'agenda_preview_btn'          => ['ro' => 'Previzualizează / printează agenda', 'da' => 'Forhåndsvis / udskriv dagsorden', 'en' => 'Preview / print agenda'],
    'send_convocation_btn'        => ['ro' => 'Trimite convocarea (draft agendă)', 'da' => 'Send indkaldelsen (udkast til dagsorden)', 'en' => 'Send convocation (draft agenda)'],
    'convocation_sent_label'      => ['ro' => 'Convocare trimisă', 'da' => 'Indkaldelse sendt', 'en' => 'Convocation sent'],
    'resend_convocation_btn'      => ['ro' => 'Retrimite convocarea', 'da' => 'Send indkaldelsen igen', 'en' => 'Resend convocation'],
    'lock_agenda_btn'             => ['ro' => 'Închide lista de propuneri și generează agenda finală', 'da' => 'Luk forslagslisten og generér den endelige dagsorden', 'en' => 'Close the proposal list and generate the final agenda'],
    'send_final_btn'              => ['ro' => 'Trimite emailul final (agendă + coduri de vot)', 'da' => 'Send den endelige email (dagsorden + stemmekoder)', 'en' => 'Send final email (agenda + vote codes)'],
    'final_sent_label'            => ['ro' => 'Email final trimis', 'da' => 'Endelig email sendt', 'en' => 'Final email sent'],
    'resend_final_btn'            => ['ro' => 'Retrimite emailul final', 'da' => 'Send den endelige email igen', 'en' => 'Resend final email'],
    'emails_sent_ok'              => ['ro' => 'Trimis cu succes la %d membri.', 'da' => 'Sendt til %d medlemmer.', 'en' => 'Sent successfully to %d members.'],
    'emails_sent_partial'         => ['ro' => 'Trimis la %d membri; %d eșuate.', 'da' => 'Sendt til %d medlemmer; %d fejlede.', 'en' => 'Sent to %d members; %d failed.'],
    'meeting_not_confirmed_error' => ['ro' => 'Confirmă mai întâi data și locul cu bestyrelsen.', 'da' => 'Bekræft først dato og sted med bestyrelsen.', 'en' => 'Confirm the date and location with the board first.'],
    'deadline_required_error'     => ['ro' => 'Setează un termen limită pentru propuneri înainte de a trimite convocarea.', 'da' => 'Angiv en frist for forslag, før du sender indkaldelsen.', 'en' => 'Set a proposal deadline before sending the convocation.'],
    'live_vote_h'                 => ['ro' => 'Control vot live', 'da' => 'Live afstemningsstyring', 'en' => 'Live vote control'],
    'live_vote_sub'               => ['ro' => 'Deschide câte un subiect pe rând. Rezultatul apare automat la toți membrii de îndată ce închizi votul.', 'da' => 'Åbn ét emne ad gangen. Resultatet vises automatisk for alle medlemmer, så snart du lukker afstemningen.', 'en' => 'Open one topic at a time. The result appears automatically to all members as soon as you close the vote.'],
    'vote_state_closed'           => ['ro' => 'Neînceput', 'da' => 'Ikke startet', 'en' => 'Not started'],
    'vote_state_open'             => ['ro' => 'DESCHIS', 'da' => 'ÅBEN', 'en' => 'OPEN'],
    'vote_state_ended'            => ['ro' => 'Încheiat', 'da' => 'Afsluttet', 'en' => 'Ended'],
    'open_vote_btn'               => ['ro' => 'Deschide votul', 'da' => 'Åbn afstemningen', 'en' => 'Open vote'],
    'close_vote_btn'              => ['ro' => 'Închide votul', 'da' => 'Luk afstemningen', 'en' => 'Close vote'],
    'reopen_vote_btn'             => ['ro' => 'Redeschide (resetează voturile)', 'da' => 'Genåbn (nulstil stemmer)', 'en' => 'Reopen (reset votes)'],
    'another_topic_open_error'    => ['ro' => 'Închide mai întâi votul curent — un singur subiect deschis o dată.', 'da' => 'Luk først den aktuelle afstemning — kun ét emne åbent ad gangen.', 'en' => "Close the current vote first — only one topic open at a time."],
    'votes_da'                    => ['ro' => 'Da', 'da' => 'Ja', 'en' => 'Yes'],
    'votes_nu'                    => ['ro' => 'Nu', 'da' => 'Nej', 'en' => 'No'],
    'votes_abtinere'              => ['ro' => 'Abținere', 'da' => 'Blank', 'en' => 'Abstain'],
    'result_passed'               => ['ro' => 'ADOPTAT', 'da' => 'VEDTAGET', 'en' => 'PASSED'],
    'result_rejected'             => ['ro' => 'RESPINS', 'da' => 'FORKASTET', 'en' => 'REJECTED'],
    'result_pending'              => ['ro' => 'fără voturi', 'da' => 'ingen stemmer', 'en' => 'no votes'],
    'late_code_h'                 => ['ro' => 'Cod pentru membru sosit târziu', 'da' => 'Kode til sent ankommet medlem', 'en' => 'Code for a late-arriving member'],
    'late_code_select_label'      => ['ro' => 'Membru', 'da' => 'Medlem', 'en' => 'Member'],
    'gen_code_btn'                => ['ro' => 'Generează / arată codul', 'da' => 'Generér / vis kode', 'en' => 'Generate / show code'],
    'existing_code_label'         => ['ro' => 'Cod existent', 'da' => 'Eksisterende kode', 'en' => 'Existing code'],

    // ── referat (proces verbal al ședinței) ──
    'referat_btn'                 => ['ro' => 'Referat', 'da' => 'Referat', 'en' => 'Minutes'],
    'referat_h1'                  => ['ro' => 'Referat ședință', 'da' => 'Mødereferat', 'en' => 'Meeting minutes'],
    'referat_back_to_meeting'     => ['ro' => '← Înapoi la ședință', 'da' => '← Tilbage til mødet', 'en' => '← Back to meeting'],
    'referat_status_draft'        => ['ro' => 'Draft', 'da' => 'Udkast', 'en' => 'Draft'],
    'referat_status_final'        => ['ro' => 'Finalizat', 'da' => 'Færdiggjort', 'en' => 'Finalized'],
    'referat_finalized_at_label'  => ['ro' => 'Finalizat la', 'da' => 'Færdiggjort den', 'en' => 'Finalized on'],
    'referat_lang_label'          => ['ro' => 'Limba referatului', 'da' => 'Referatets sprog', 'en' => 'Minutes language'],
    'referat_content_label'       => ['ro' => 'Conținutul referatului', 'da' => 'Referatets indhold', 'en' => 'Minutes content'],
    'referat_content_ph'          => ['ro' => 'Deschiderea ședinței, constatări, discuții, decizii, observații...', 'da' => 'Mødets åbning, konstateringer, drøftelser, beslutninger, bemærkninger...', 'en' => 'Meeting opening, findings, discussions, decisions, remarks...'],
    'referat_content_hint'        => ['ro' => 'Ordinea de zi și rezultatul votului (mai jos) se adaugă automat la finalul documentului — aici scrii doar partea narativă.', 'da' => 'Dagsordenen og afstemningsresultatet (nedenfor) tilføjes automatisk til sidst i dokumentet — her skriver du kun den beskrivende del.', 'en' => 'The agenda and the vote result (below) are appended automatically at the end of the document — here you only write the narrative part.'],
    'referat_save_draft_btn'      => ['ro' => 'Salvează draft', 'da' => 'Gem udkast', 'en' => 'Save draft'],
    'referat_finalize_btn'        => ['ro' => 'Generează PDF și salvează în Documente', 'da' => 'Generér PDF og gem i Dokumenter', 'en' => 'Generate PDF and save to Documents'],
    'referat_regenerate_btn'      => ['ro' => 'Regenerează PDF', 'da' => 'Genopret PDF', 'en' => 'Regenerate PDF'],
    'referat_download_btn'        => ['ro' => '↓ Descarcă PDF', 'da' => '↓ Download PDF', 'en' => '↓ Download PDF'],
    'referat_view_in_documents_btn' => ['ro' => 'Vezi în Documente', 'da' => 'Se i Dokumenter', 'en' => 'View in Documents'],
    'referat_not_public_notice'   => ['ro' => 'Referatul se salvează în Documente ca fișier privat (nu apare pe pagina publică de transparență).', 'da' => 'Referatet gemmes i Dokumenter som en privat fil (vises ikke på den offentlige gennemsigtighedsside).', 'en' => 'The minutes are saved to Documents as a private file (not shown on the public transparency page).'],
    'referat_agenda_preview_h'    => ['ro' => 'Ordinea de zi (auto-generată)', 'da' => 'Dagsorden (auto-genereret)', 'en' => 'Agenda (auto-generated)'],
    'referat_votes_annex_h'       => ['ro' => 'Anexă — rezultatul înregistrat al votului', 'da' => 'Bilag — det registrerede afstemningsresultat', 'en' => 'Annex — recorded vote result'],
    'referat_no_items'            => ['ro' => 'Nu există subiecte în bucket-ul acestei ședințe încă — adaugă subiecte în bucket înainte de a genera referatul.', 'da' => 'Der er endnu ingen emner i mødets pulje — tilføj emner til puljen, før du genererer referatet.', 'en' => 'There are no topics in this meeting\'s bucket yet — add topics to the bucket before generating the minutes.'],
    'referat_generated_ok'        => ['ro' => 'PDF generat și salvat în Documente.', 'da' => 'PDF genereret og gemt i Dokumenter.', 'en' => 'PDF generated and saved to Documents.'],
    'referat_generated_error'     => ['ro' => 'Eroare la generarea PDF-ului', 'da' => 'Fejl ved generering af PDF', 'en' => 'Error generating the PDF'],
    'referat_saved_draft_ok'      => ['ro' => 'Draft salvat.', 'da' => 'Udkast gemt.', 'en' => 'Draft saved.'],
    'referat_council_vote_detail_h' => ['ro' => 'Vot nominal', 'da' => 'Navneafstemning', 'en' => 'Roll-call vote'],
    'referat_tie_president_label' => ['ro' => 'Egalitate — decide votul președintelui', 'da' => 'Stemmelighed — formandens stemme afgør', 'en' => 'Tie — the president\'s vote decides'],

    // ── agenda printabilă ──
    'agenda_print_title'          => ['ro' => 'Ordinea de zi', 'da' => 'Dagsorden', 'en' => 'Order of the day'],
    'print_btn'                   => ['ro' => 'Printează', 'da' => 'Udskriv', 'en' => 'Print'],
    'agenda_draft_h'              => ['ro' => 'Ordinea de zi (draft)', 'da' => 'Dagsorden (udkast)', 'en' => 'Agenda (draft)'],
    'agenda_final_h'              => ['ro' => 'Ordinea de zi (finală)', 'da' => 'Dagsorden (endelig)', 'en' => 'Agenda (final)'],
    'agenda_draft_notice'         => ['ro' => 'Draft — pot apărea modificări până la termenul limită de propuneri.', 'da' => 'Udkast — der kan forekomme ændringer frem til fristen for forslag.', 'en' => 'Draft — changes may occur until the proposal deadline.'],

    // ── email-uri convocare / final ──
    'email_hello_prefix'          => ['ro' => 'Bună', 'da' => 'Hej', 'en' => 'Hello'],
    'convocation_subject_prefix'  => ['ro' => 'Convocare adunare generală —', 'da' => 'Indkaldelse til generalforsamling —', 'en' => 'General assembly convocation —'],
    'convocation_intro'           => ['ro' => 'Conform statutului, vă convocăm la adunarea generală a asociației Front Door:', 'da' => 'I henhold til vedtægterne indkalder vi hermed til foreningen Front Doors generalforsamling:', 'en' => "Per the bylaws, we hereby convene Front Door's general assembly:"],
    'proposals_intro'             => ['ro' => 'Propuneri de subiecte se trimit la', 'da' => 'Forslag til emner sendes til', 'en' => 'Topic proposals can be sent to'],
    'proposals_deadline_prefix'   => ['ro' => 'Termen limită:', 'da' => 'Frist:', 'en' => 'Deadline:'],
    'final_subject_prefix'        => ['ro' => 'Adunare generală — agendă finală și cod de vot —', 'da' => 'Generalforsamling — endelig dagsorden og stemmekode —', 'en' => 'General assembly — final agenda and vote code —'],
    'final_email_intro'           => ['ro' => 'Vă reamintim de adunarea generală a asociației Front Door. Lista de propuneri este acum închisă.', 'da' => 'Vi minder om foreningen Front Doors generalforsamling. Forslagslisten er nu lukket.', 'en' => "A reminder of Front Door's general assembly. The proposal list is now closed."],
    'proposals_closed_notice'     => ['ro' => 'Lista de propuneri este închisă — mai jos e versiunea finală a agendei.', 'da' => 'Forslagslisten er lukket — nedenfor er den endelige version af dagsordenen.', 'en' => 'The proposal list is closed — below is the final version of the agenda.'],
    'your_vote_code_label'        => ['ro' => 'Codul tău de vot', 'da' => 'Din stemmekode', 'en' => 'Your voting code'],

    // ── pagina publică de vot ──
    'vote_page_title'             => ['ro' => 'Vot — Front Door', 'da' => 'Afstemning — Front Door', 'en' => 'Vote — Front Door'],
    'vote_code_prompt'            => ['ro' => 'Introdu codul primit pe email', 'da' => 'Indtast koden fra din email', 'en' => 'Enter the code from your email'],
    'vote_code_submit_btn'        => ['ro' => 'Continuă', 'da' => 'Fortsæt', 'en' => 'Continue'],
    'vote_invalid_code'           => ['ro' => 'Cod invalid. Verifică emailul primit sau cere un cod nou de la conducere.', 'da' => 'Ugyldig kode. Tjek din email, eller bed bestyrelsen om en ny kode.', 'en' => 'Invalid code. Check your email, or ask the board for a new code.'],
    'vote_welcome_prefix'         => ['ro' => 'Bine ai venit', 'da' => 'Velkommen', 'en' => 'Welcome'],
    'vote_no_topic_open'          => ['ro' => 'Niciun subiect deschis pentru vot momentan. Pagina se actualizează automat.', 'da' => 'Intet emne åbent til afstemning lige nu. Siden opdateres automatisk.', 'en' => 'No topic currently open for voting. This page updates automatically.'],
    'vote_cast_prompt'            => ['ro' => 'Se votează acum:', 'da' => 'Der stemmes nu om:', 'en' => 'Now voting:'],
    'vote_submit_btn'             => ['ro' => 'Trimite votul', 'da' => 'Afgiv stemme', 'en' => 'Cast vote'],
    'vote_already_cast'           => ['ro' => 'Votul tău a fost înregistrat. Votul e definitiv și nu mai poate fi schimbat.', 'da' => 'Din stemme er registreret. Stemmen er endelig og kan ikke ændres.', 'en' => 'Your vote has been recorded. The vote is final and cannot be changed.'],
    'vote_thanks'                 => ['ro' => 'Mulțumim pentru vot!', 'da' => 'Tak for din stemme!', 'en' => 'Thanks for voting!'],
    'vote_results_h'              => ['ro' => 'Rezultat', 'da' => 'Resultat', 'en' => 'Result'],
    'vote_upcoming_h'             => ['ro' => 'Următoarele subiecte', 'da' => 'Kommende emner', 'en' => 'Upcoming topics'],
    'vote_ended_topics_h'         => ['ro' => 'Subiecte încheiate', 'da' => 'Afsluttede emner', 'en' => 'Closed topics'],
    'change_code_btn'             => ['ro' => 'Alt cod', 'da' => 'Anden kode', 'en' => 'Different code'],
    'topics_voting_notice'        => ['ro' => 'Votul nu se mai face aici — subiectele alese pentru ședință se votează live, în timpul adunării, cu un cod trimis pe email.', 'da' => 'Der stemmes ikke længere her — de udvalgte emner behandles live under generalforsamlingen med en kode sendt på email.', 'en' => "Voting no longer happens here — topics selected for the meeting are voted live during the assembly, using a code sent by email."],

    // ── limba membrului / traducere automată propuneri ──
    'member_lang_field'           => ['ro' => 'Limba membrului', 'da' => 'Medlemmets sprog', 'en' => "Member's language"],
    'member_lang_hint'             => ['ro' => 'Limba în care primește emailuri (convocare, agendă). Presetată din formularul de înscriere.', 'da' => 'Sproget, medlemmet modtager emails på (indkaldelse, dagsorden). Forudfyldt fra tilmeldingsformularen.', 'en' => 'The language emails go out in (convocation, agenda). Preset from the sign-up form.'],
    'orig_lang_field'              => ['ro' => 'Limba în care scrii', 'da' => 'Sproget, du skriver på', 'en' => 'Language you are writing in'],
    'translations_h'               => ['ro' => 'Traduceri automate', 'da' => 'Automatiske oversættelser', 'en' => 'Automatic translations'],
    'translations_sub'             => ['ro' => 'Generate automat la salvare. Le poți corecta mai jos — bifează „am editat manual” ca să nu fie suprascrise data viitoare.', 'da' => 'Genereret automatisk ved gemning. Du kan rette dem nedenfor — marker „redigeret manuelt”, så de ikke overskrives næste gang.', 'en' => 'Generated automatically on save. You can correct them below — check "manually edited" so they aren\'t overwritten next time.'],
    'manually_edited_checkbox'     => ['ro' => 'Am editat manual traducerea de mai sus — nu o retraduce automat', 'da' => 'Jeg har redigeret oversættelsen ovenfor manuelt — oversæt ikke automatisk igen', 'en' => "I manually edited the translation above — don't auto-translate it again"],
    'translating_notice'           => ['ro' => 'Titlul și descrierea se traduc automat în celelalte două limbi la salvare.', 'da' => 'Titel og beskrivelse oversættes automatisk til de to andre sprog ved gemning.', 'en' => 'Title and description are auto-translated into the other two languages on save.'],
    'translating_notice_event'     => ['ro' => 'Titlul și descrierea se traduc automat în celelalte două limbi (RO/DA/EN) la salvare.', 'da' => 'Titel og beskrivelse oversættes automatisk til de to andre sprog (RO/DA/EN) ved gemning.', 'en' => 'Title and description are auto-translated into the other two languages (RO/DA/EN) on save.'],
    'social_auto_h'                 => ['ro' => 'Postează pe rețele', 'da' => 'Del på sociale medier', 'en' => 'Post to social media'],
    'social_auto_sub'                => ['ro' => 'Facebook nu mai permite crearea de Evenimente reale prin API pentru aplicații obișnuite (doar citire) — creezi Evenimentul manual pe Facebook, restul se automatizează.', 'da' => 'Facebook tillader ikke længere oprettelse af rigtige Events via API for almindelige apps (kun læsning) — du opretter selve Event\'et manuelt på Facebook, resten automatiseres.', 'en' => "Facebook no longer allows creating real Events via API for ordinary apps (read-only) — you create the actual Event manually on Facebook, everything else is automated."],
    'fb_event_step_h'               => ['ro' => '1. Evenimentul pe Facebook', 'da' => '1. Facebook-eventet', 'en' => '1. The Facebook Event'],
    'fb_event_step_sub'             => ['ro' => 'Apasă „Deschide Facebook”, apoi „Copiază detaliile” și lipește-le în formularul Facebook (titlu, dată, oră, locație, descriere) — durează sub un minut. Când Evenimentul e publicat pe Facebook, copiază link-ul lui aici și salvează.', 'da' => 'Klik „Åbn Facebook”, derefter „Kopiér detaljer” og indsæt dem i Facebooks formular (titel, dato, tid, sted, beskrivelse) — tager under et minut. Når eventet er offentliggjort på Facebook, kopiér linket hertil og gem.', 'en' => 'Click "Open Facebook", then "Copy details" and paste them into Facebook\'s form (title, date, time, location, description) — takes under a minute. Once the Event is published on Facebook, copy its link here and save.'],
    'open_fb_create_event_btn'      => ['ro' => 'Deschide Facebook → Creează Eveniment', 'da' => 'Åbn Facebook → Opret event', 'en' => 'Open Facebook → Create Event'],
    'copy_details_btn'              => ['ro' => 'Copiază detaliile', 'da' => 'Kopiér detaljer', 'en' => 'Copy details'],
    'fb_event_url_label'            => ['ro' => 'Link Eveniment Facebook', 'da' => 'Link til Facebook-event', 'en' => 'Facebook Event link'],
    'save_fb_event_url_btn'         => ['ro' => 'Salvează link-ul', 'da' => 'Gem link', 'en' => 'Save link'],
    'fb_post_step_h'                => ['ro' => '2. Postare pe Pagina de Facebook', 'da' => '2. Opslag på Facebook-siden', 'en' => '2. Post to the Facebook Page'],
    'fb_post_step_sub'              => ['ro' => 'Alege mai jos în ce limbi să fie textul, apoi generează-l — poți edita înainte de postare. Include link-ul Evenimentului dacă l-ai salvat mai sus.', 'da' => 'Vælg herunder, hvilke sprog teksten skal være på, og generér den — du kan redigere før opslag. Inkluderer event-linket, hvis du har gemt det ovenfor.', 'en' => 'Choose below which languages the text should use, then generate it — editable before posting. Includes the Event link if you saved one above.'],
    'choose_langs_label'            => ['ro' => 'Limbi în postare', 'da' => 'Sprog i opslaget', 'en' => 'Languages in the post'],
    'generate_text_btn'             => ['ro' => 'Generează textul', 'da' => 'Generér tekst', 'en' => 'Generate text'],
    'ig_facebook_mention'           => ['ro' => 'Detalii și participare — vezi Facebook', 'da' => 'Detaljer og tilmelding — se Facebook', 'en' => 'Details and RSVP — see Facebook'],
    'post_to_facebook_btn'          => ['ro' => 'Postează pe Facebook', 'da' => 'Del på Facebook', 'en' => 'Post to Facebook'],
    'fb_not_configured_notice'      => ['ro' => 'Facebook nu e configurat încă — lipsesc FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN din config.php.', 'da' => 'Facebook er ikke konfigureret endnu — FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN mangler i config.php.', 'en' => 'Facebook is not configured yet — FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN are missing from config.php.'],
    'fb_posted_label'               => ['ro' => 'Postat pe Facebook', 'da' => 'Delt på Facebook', 'en' => 'Posted to Facebook'],
    'view_post_link'                => ['ro' => 'Vezi postarea', 'da' => 'Se opslaget', 'en' => 'View post'],
    'ig_post_step_h'                => ['ro' => '3. Postare pe Instagram', 'da' => '3. Opslag på Instagram', 'en' => '3. Post to Instagram'],
    'ig_post_step_sub'              => ['ro' => 'Instagram nu permite link-uri clicabile în descriere — postarea menționează Facebook, în loc de link. Necesită o poză de copertă la eveniment.', 'da' => 'Instagram tillader ikke klikbare links i beskrivelsen — opslaget nævner Facebook i stedet for et link. Kræver et forsidebillede på eventet.', 'en' => "Instagram doesn't allow clickable links in captions — the post mentions Facebook instead of a link. Requires a cover image on the event."],
    'post_to_instagram_btn'         => ['ro' => 'Postează pe Instagram', 'da' => 'Del på Instagram', 'en' => 'Post to Instagram'],
    'ig_not_configured_notice'      => ['ro' => 'Instagram nu e configurat încă — lipsesc IG_BUSINESS_ACCOUNT_ID / FB_PAGE_ACCESS_TOKEN din config.php.', 'da' => 'Instagram er ikke konfigureret endnu — IG_BUSINESS_ACCOUNT_ID / FB_PAGE_ACCESS_TOKEN mangler i config.php.', 'en' => 'Instagram is not configured yet — IG_BUSINESS_ACCOUNT_ID / FB_PAGE_ACCESS_TOKEN are missing from config.php.'],
    'ig_posted_label'               => ['ro' => 'Postat pe Instagram', 'da' => 'Delt på Instagram', 'en' => 'Posted to Instagram'],
    'ig_needs_cover_notice'         => ['ro' => 'Adaugă o poză de copertă evenimentului (din pagina de editare) ca să poți posta pe Instagram — Instagram nu acceptă postări doar-text.', 'da' => 'Tilføj et forsidebillede til eventet (fra redigeringssiden) for at kunne dele på Instagram — Instagram tillader ikke opslag uden billede.', 'en' => "Add a cover image to the event (from the edit page) to post to Instagram — Instagram doesn't allow text-only posts."],
    'auto_post_link'                => ['ro' => 'Postează automat', 'da' => 'Auto-del', 'en' => 'Auto-post'],
    'repost_btn'                    => ['ro' => 'Postează din nou', 'da' => 'Del igen', 'en' => 'Post again'],
    'invalid_url'                   => ['ro' => 'Link invalid — trebuie să înceapă cu https://facebook.com/', 'da' => 'Ugyldigt link — skal starte med https://facebook.com/', 'en' => 'Invalid link — must start with https://facebook.com/'],

    // ── ședință de consiliu (bestyrelsesmøde) ──
    'meeting_type_council'         => ['ro' => 'Ședință de consiliu (bestyrelse)', 'da' => 'Bestyrelsesmøde', 'en' => 'Board meeting'],
    'council_badge'                => ['ro' => 'CONSILIU', 'da' => 'BESTYRELSE', 'en' => 'BOARD'],
    'council_only_notice'          => ['ro' => 'Ședință de consiliu — convocarea și votul sunt doar pentru bestyrelse (președinte, vicepreședinte, trezorier, consilieri). Revizorul nu participă la vot, conform statutului (§10.3).', 'da' => 'Bestyrelsesmøde — indkaldelse og afstemning er kun for bestyrelsen (formand, næstformand, kasserer, bestyrelsesmedlemmer). Revisor deltager ikke i afstemningen, jf. vedtægternes §10.3.', 'en' => "Board meeting — the convocation and vote are for the board only (chair, vice-chair, treasurer, board members). The auditor doesn't take part in the vote, per §10.3 of the bylaws."],
    'send_convocation_council_btn' => ['ro' => 'Trimite convocarea către consiliu', 'da' => 'Send indkaldelsen til bestyrelsen', 'en' => 'Send convocation to the board'],
    'send_final_council_btn'       => ['ro' => 'Trimite emailul final către consiliu', 'da' => 'Send den endelige email til bestyrelsen', 'en' => 'Send final email to the board'],
    'council_vote_from_panel_notice'=> ['ro' => 'Votul se face direct din panel, nu prin cod — accesează pagina ședinței:', 'da' => 'Afstemningen foregår direkte i panelet, ikke via kode — gå til mødesiden:', 'en' => 'Voting happens directly in the panel, not by code — go to the meeting page:'],
    'council_vote_h'                => ['ro' => 'Votul consiliului (nominal)', 'da' => 'Bestyrelsens afstemning (navngivet)', 'en' => "Board vote (named)"],
    'council_vote_sub'              => ['ro' => 'Fiecare membru al bestyrelsen votează direct de aici. Votul e nominal, ca la procesul verbal — se poate schimba cât timp subiectul e deschis.', 'da' => 'Hvert bestyrelsesmedlem stemmer direkte herfra. Afstemningen er navngivet, som i referatet — kan ændres, mens emnet er åbent.', 'en' => 'Each board member votes directly here. The vote is named, as in the minutes — it can be changed while the topic is open.'],
    'your_vote_label'               => ['ro' => 'Votul tău', 'da' => 'Din stemme', 'en' => 'Your vote'],
    'not_board_member_notice'       => ['ro' => 'Contul tău nu are drept de vot la această ședință de consiliu — fie ești revizor (prezent doar pentru transparență, fără vot conform statutului), fie ești consilier și nu ai fost desemnat votant pentru această ședință.', 'da' => 'Din konto har ikke stemmeret på dette bestyrelsesmøde — enten er du revisor (kun til stede for gennemsigtighed, uden stemmeret ifølge vedtægterne), eller også er du bestyrelsesmedlem, som ikke er udpeget som stemmeberettiget til netop dette møde.', 'en' => "Your account doesn't have voting rights at this council meeting — either you're the auditor (present only for transparency, no vote per the bylaws), or you're a councillor who wasn't designated a voter for this specific meeting."],
    'council_voters_h'              => ['ro' => 'Votanți la această ședință', 'da' => 'Stemmeberettigede til dette møde', 'en' => 'Voters for this meeting'],
    'council_voters_sub'            => ['ro' => 'Președintele, vicepreședintele și trezorierul votează mereu. Alege mai jos care dintre consilieri au drept de vot la ședința curentă — se decide la începutul fiecărei ședințe. Revizorul e convocat doar ca observator, fără vot.', 'da' => 'Formand, næstformand og kasserer stemmer altid. Vælg herunder hvilke bestyrelsesmedlemmer (rådgivere), der har stemmeret på det aktuelle møde — det besluttes ved starten af hvert møde. Revisor indkaldes kun som observatør, uden stemmeret.', 'en' => 'The chair, vice-chair, and treasurer always vote. Below, choose which councillors have voting rights at this meeting — this is decided at the start of each meeting. The auditor is convened only as an observer, with no vote.'],
    'council_permanent_voters_label' => ['ro' => 'Votează mereu', 'da' => 'Stemmer altid', 'en' => 'Always votes'],
    'council_voters_save_btn'       => ['ro' => 'Salvează votanții', 'da' => 'Gem stemmeberettigede', 'en' => 'Save voters'],
    'council_observer_badge'        => ['ro' => 'observator, fără vot', 'da' => 'observatør, uden stemme', 'en' => 'observer, no vote'],
    'tie_president_decides'         => ['ro' => 'Egalitate — decide votul președintelui', 'da' => 'Stemmelighed — formandens stemme afgør', 'en' => "Tie — the chair's vote decides"],
    'president_voted_prefix'        => ['ro' => 'Președintele a votat:', 'da' => 'Formanden stemte:', 'en' => 'The chair voted:'],
    'president_not_voted_yet'       => ['ro' => 'Președintele nu a votat încă.', 'da' => 'Formanden har endnu ikke stemt.', 'en' => 'The chair has not voted yet.'],
]);

/**
 * Traducere pentru cheia $key, în limba curentă a interfeței (ui_lang()).
 * Cade pe română dacă limba curentă lipsește pentru acea cheie, și pe
 * cheia însăși dacă nici româna nu există (ca să fie vizibil în UI că
 * lipsește o traducere, nu o pagină goală).
 */
function t(string $key, ?string $lang = null): string {
    $lang = $lang ?? ui_lang();
    return TR[$key][$lang] ?? (TR[$key]['ro'] ?? $key);
}

/* =============================================================
   PERMISIUNI — 3 roluri:
     admin   (președinte/vicepreședinte/trezorier) — acces total.
     revizor — la fel ca și consilier acum: adminul bifează per-tab și
              per-acțiune ce poate face, salvat ca JSON în
              bf_users.permissions (editabil oricând din Setări →
              Utilizatori, inclusiv după creare). REVIZOR_PERMISSIONS de
              mai jos rămâne doar ca set implicit — se aplică o singură
              dată, la prima desemnare, ca punct de plecare rezonabil
              (vizualizare pe Evenimente/Proiecte/Regnskab + propuneri pe
              Propuneri), și ca fallback pentru conturile mai vechi cărui
              nu li s-a editat încă explicit accesul.
     member  (consilier) — nimic implicit; adminul bifează per-tab și
              per-acțiune ce poate face fiecare, salvat ca JSON în
              bf_users.permissions.
   ============================================================= */

define('CONSILIER_PERMISSION_SCHEMA', [
    'events'    => ['view' => 'Vede evenimente', 'create' => 'Poate crea evenimente noi', 'edit_own' => 'Poate edita evenimentele proprii', 'edit_any' => 'Poate edita orice eveniment', 'manage' => 'Poate suspenda/reactiva/anula/șterge evenimente'],
    'projects'  => ['view' => 'Vede proiecte (inclusiv detalii/buget)', 'create' => 'Poate crea proiecte noi', 'edit' => 'Poate edita proiecte (inclusiv detalii/buget)', 'manage' => 'Poate finaliza/anula/șterge proiecte'],
    'members'   => ['view' => 'Vede cererile de membership', 'edit' => 'Poate schimba status, trimite email, edita profil membru', 'delete' => 'Poate șterge cereri de membership'],
    'topics'    => ['view' => 'Vede propuneri și adunări', 'propose' => 'Poate propune subiecte', 'edit_own' => 'Poate edita/șterge propriile propuneri', 'manage' => 'Poate crea/gestiona adunări, bucket-ul de vot și emailurile de convocare'],
    'documents' => ['view' => 'Vede documentele de transparență', 'manage' => 'Poate încărca/șterge documente'],
    'regnskab'  => ['view' => 'Vede regnskab', 'manage' => 'Poate gestiona regnskab (inclusiv linkul către Google Sheets sursă)'],
]);

// Setul implicit de permisiuni pentru un revizor nou desemnat — folosit doar
// ca prebifare inițială (vezi grant_panel_account/add_user/edit_position) și
// ca fallback în has_perm() pentru conturile de revizor care nu au încă
// nimic salvat explicit în bf_users.permissions. Odată editat din UI,
// permissions-ul stocat ia locul acestui set complet.
const REVIZOR_PERMISSIONS = [
    'events'   => ['view' => 1],
    'projects' => ['view' => 1],
    'topics'   => ['view' => 1, 'propose' => 1, 'edit_own' => 1],
    'regnskab' => ['view' => 1],
];

/**
 * Asigură coloana bf_users.permissions (JSON cu accesul custom al unui
 * consilier). Migrare "lazy", ca restul aplicației.
 */
function ensure_user_permissions_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec('ALTER TABLE bf_users ADD COLUMN permissions TEXT NULL'); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Poza de profil a unui cont din panou (Setări → Profil meu). Migrată aici
 * dintr-un ALTER inline din settings.php ca să existe garantat oriunde e
 * nevoie să citim coloana — inclusiv în delete_panel_account(), care rulează
 * și din settings.php, și din member-profile.php.
 */
function ensure_user_avatar_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec('ALTER TABLE bf_users ADD COLUMN avatar VARCHAR(255) NULL'); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Leagă real (prin id, nu prin potrivire de email) un cont din panoul admin
 * (bf_users) de profilul de membru (membership_requests) din care a fost
 * creat — bf_users.member_id. Necesar pentru că, odată cu emailul secundar
 * (profesional) folosit la login, emailul din bf_users poate diferi de
 * emailul de contact al membrului, deci nu mai putem identifica legătura
 * doar comparând coloana email.
 *
 * Backfill o singură dată: conturile create înainte de această coloană se
 * leagă retroactiv, potrivite după email — ultima oară că se mai folosește
 * potrivirea prin email pentru asta.
 */
function ensure_user_member_link_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec('ALTER TABLE bf_users ADD COLUMN member_id INT UNSIGNED NULL'); } catch (PDOException $e) {}
    try { $pdo->exec('ALTER TABLE bf_users ADD KEY idx_member_id (member_id)'); } catch (PDOException $e) {}
    try {
        $pdo->exec("UPDATE bf_users u JOIN membership_requests m ON m.email = u.email
                     SET u.member_id = m.id WHERE u.member_id IS NULL");
    } catch (PDOException $e) {}
    $done = true;
}

/**
 * Pozițiile de care poate exista o singură persoană activă deodată în
 * panoul admin (bestyrelsen + revizor). „Consilier" nu e exclusiv — pot fi
 * mai mulți consilieri simultan.
 */
const EXCLUSIVE_POSITIONS = ['presedinte', 'vicepresedinte', 'trezorier', 'revizor'];

/**
 * Contul activ (dacă există) care ocupă deja o poziție exclusivă, altul
 * decât $exclude_user_id. Folosit la reasignarea unei poziții (ex. când
 * cineva nou preia „președinte") ca să știm pe cine dezactivăm automat.
 */
function find_active_position_holder(PDO $pdo, string $position, ?int $exclude_user_id = null): ?array {
    if (!in_array($position, EXCLUSIVE_POSITIONS, true)) return null;
    $sql = 'SELECT * FROM bf_users WHERE position = ? AND active = 1';
    $params = [$position];
    if ($exclude_user_id !== null) { $sql .= ' AND id <> ?'; $params[] = $exclude_user_id; }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * Scoate definitiv un cont din panoul admin — folosit ori de câte ori cineva
 * e „dezactivat" de pe o poziție (buton manual din Setări → Utilizatori) ori
 * înlocuit automat pe o poziție exclusivă (find_active_position_holder).
 * Nu doar setează active=0: șterge chiar rândul din bf_users (plus poza de
 * profil, dacă are), ca postul să dispară din listă și profilul de membru
 * legat să redevină liber pentru o desemnare nouă — nu mai există concept de
 * „cont dezactivat, dar păstrat pe fundal".
 */
function delete_panel_account(PDO $pdo, int $uid): void {
    $row = $pdo->prepare('SELECT avatar FROM bf_users WHERE id = ?');
    $row->execute([$uid]);
    $row = $row->fetch();
    if ($row && !empty($row['avatar'])) {
        @unlink(dirname(__DIR__) . '/' . ltrim($row['avatar'], '/'));
    }
    $pdo->prepare('DELETE FROM bf_users WHERE id = ?')->execute([$uid]);
}

/**
 * Emailul „principal" real al unui membru — cel pe care se trimite orice
 * comunicare (welcome, resetare parolă, emailuri din members.php) și cel
 * folosit ca login pe contul din panou, dacă are unul. Regulă simplă și
 * fără alegere manuală: dacă emailul de asociație (secundar) e completat,
 * el devine automat principal; altfel rămâne cel personal.
 */
function member_primary_email(array $member): string {
    return !empty($member['email_secondary']) ? $member['email_secondary'] : $member['email'];
}

// Câți consilieri poate avea asociația simultan — a 6-a desemnare trebuie
// blocată explicit (nu înlocuită automat, ca la pozițiile exclusive de mai
// sus), pentru că adminul trebuie să aleagă manual pe cine scoate.
define('MAX_CONSILIERI', 5);

/**
 * Câte conturi active ocupă deja o anumită poziție — folosit pentru plafonul
 * de consilieri (MAX_CONSILIERI). $exclude_user_id exclude un cont din
 * numărătoare (ex. la editarea poziției unui consilier deja existent, ca să
 * nu se numere singur).
 */
function count_active_by_position(PDO $pdo, string $position, ?int $exclude_user_id = null): int {
    $sql = 'SELECT COUNT(*) FROM bf_users WHERE position = ? AND active = 1';
    $params = [$position];
    if ($exclude_user_id !== null) { $sql .= ' AND id <> ?'; $params[] = $exclude_user_id; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Verificare comună pentru orice acțiune care creează/reasignează o poziție
 * (add_user, edit_position, grant_panel_account): întoarce un mesaj de
 * eroare dacă poziția nu poate fi ocupată acum, sau null dacă e OK.
 * $exclude_user_id = contul curent, atunci când reasignăm (nu se numără/nu
 * se înlocuiește singur). Nu se ocupă de poziții exclusive aici — acelea se
 * rezolvă prin înlocuire automată (find_active_position_holder), nu prin
 * blocare.
 */
function position_capacity_error(PDO $pdo, string $position, ?int $exclude_user_id = null): ?string {
    if ($position === 'consilier') {
        $count = count_active_by_position($pdo, 'consilier', $exclude_user_id);
        if ($count >= MAX_CONSILIERI) {
            return 'Limita de ' . MAX_CONSILIERI . ' consilieri e atinsă. Dezactivează sau schimbă poziția unui consilier existent înainte să desemnezi altul.';
        }
    }
    return null;
}

function user_permissions(array $user): array {
    if (empty($user['permissions'])) return [];
    $decoded = json_decode($user['permissions'], true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Construiește JSON-ul de permisiuni dintr-un array $_POST['perm'][tab][action]=1,
 * validat strict față de CONSILIER_PERMISSION_SCHEMA (nu acceptăm chei arbitrare).
 */
function build_permissions_json(array $posted): string {
    $out = [];
    foreach (CONSILIER_PERMISSION_SCHEMA as $tab => $actions) {
        foreach (array_keys($actions) as $action) {
            if (!empty($posted[$tab][$action])) $out[$tab][$action] = 1;
        }
    }
    return json_encode($out);
}

/**
 * Singurul punct de decizie pentru "poate userul X face acțiunea Y pe tab-ul Z".
 * Folosit atât pentru vizibilitatea tab-urilor (action implicit 'view'), cât
 * și pentru gating-ul acțiunilor concrete din fiecare pagină.
 */
function has_perm(array $user, string $tab, string $action = 'view'): bool {
    $role = $user['role'] ?? '';
    if ($role === 'admin') return true;
    if ($tab === 'settings' && $action === 'view') return true; // profilul propriu, mereu vizibil
    $perms = user_permissions($user);
    // Revizor fără nimic salvat explicit încă (cont vechi, sau nou creat
    // fără bife) — cade pe setul implicit istoric, ca accesul lui să nu
    // dispară brusc doar pentru că nimeni nu i-a editat încă permisiunile.
    if ($role === 'revizor' && empty($perms)) {
        $perms = REVIZOR_PERMISSIONS;
    }
    return !empty($perms[$tab][$action]);
}

/**
 * require_login() + verificare permisiune. Înlocuiește require_login()/
 * require_director() pe paginile ale căror acces variază acum pe rol.
 */
function require_perm(string $tab, string $action = 'view'): array {
    $u = require_login();
    if (!has_perm($u, $tab, $action)) {
        flash('error', 'Nu ai acces la această secțiune.');
        header('Location: /admin/settings.php'); exit;
    }
    return $u;
}

function can_edit_event(array $user, int $event_owner_id): bool {
    if (has_perm($user, 'events', 'edit_any')) return true;
    return (int)$user['id'] === $event_owner_id && has_perm($user, 'events', 'edit_own');
}

/**
 * Randează matricea de bife tab × acțiune pentru un consilier. Reutilizată
 * din settings.php (formularul de user nou / editare poziție) și din
 * member-profile.php (desemnarea unui membru ca și consilier).
 * $current = array deja decodat (din user_permissions()) pentru pre-bifare.
 * $prefix  = numele câmpului HTML, ex. "perm" → perm[events][view].
 */
function render_permission_matrix(array $current = [], string $name = 'perm', ?string $id_prefix = null): void {
    $id_prefix = $id_prefix ?? $name;
    foreach (CONSILIER_PERMISSION_SCHEMA as $tab => $actions): ?>
      <div style="margin-bottom:14px">
        <div style="font-size:12px;font-weight:700;color:rgba(255,255,255,.6);margin-bottom:6px;text-transform:capitalize"><?= e($tab) ?></div>
        <div style="display:flex;flex-direction:column;gap:5px">
          <?php foreach ($actions as $action => $label): $id = $id_prefix.'-'.$tab.'-'.$action; ?>
            <label class="check-row" style="font-size:13px">
              <input type="checkbox" name="<?= e($name) ?>[<?= e($tab) ?>][<?= e($action) ?>]" id="<?= e($id) ?>" value="1" <?= !empty($current[$tab][$action]) ? 'checked' : '' ?>>
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach;
}

function csrf_token(): string {
    if (empty($_SESSION['fd_csrf'])) {
        $_SESSION['fd_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['fd_csrf'];
}

function csrf_verify(): void {
    if (!hash_equals($_SESSION['fd_csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); die('Token invalid.');
    }
}

/**
 * Generează o parolă temporară aleatorie (pentru useri noi / resetare parolă).
 * Nu mai folosim un string fix — asta era vizibil oricui citea codul sursă.
 */
function gen_temp_password(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $pwd = '';
    for ($i = 0; $i < 12; $i++) {
        $pwd .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $pwd;
}

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * Procesează un upload de avatar din $_FILES['avatar'] (dacă a fost trimis
 * unul valid), șterge fișierul vechi dacă e înlocuit, și întoarce calea
 * nouă (relativă, cum se stochează în DB) — sau $current neschimbat dacă
 * nu s-a trimis nimic sau fișierul nu trece validarea. Aceeași regulă ca
 * la coperțile de eveniment: nu avem încredere în Content-Type sau
 * extensia trimisă de client, verificăm imaginea reală cu getimagesize()
 * și derivăm extensia de acolo.
 */
function handle_avatar_upload(string $prefix, int $owner_id, ?string $current): ?string {
    if (empty($_FILES['avatar']['tmp_name']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        return $current;
    }
    $file = $_FILES['avatar'];
    $allowed_types = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $info = @getimagesize($file['tmp_name']);
    if ($file['size'] > 3 * 1024 * 1024 || !$info || !isset($allowed_types[$info[2]])) {
        return $current;
    }
    $ext   = $allowed_types[$info[2]];
    $fname = $prefix . '-' . $owner_id . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest  = dirname(__DIR__) . '/assets/avatars/' . $fname;
    @mkdir(dirname($dest), 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return $current;
    }
    if ($current) { @unlink(dirname(__DIR__) . '/' . ltrim($current, '/')); }
    return 'assets/avatars/' . $fname;
}

/**
 * Asigură coloanele/tabelele pentru profilul de membru (cotizație, scutire,
 * voluntariat, relații de familie, contribuții la proiecte). Apelată din
 * members.php, member-profile.php și members-export.php — pattern-ul de
 * migrare "lazy" e deja folosit în restul aplicației (events.php, topics.php,
 * settings.php), îl păstrăm consistent aici.
 */
function ensure_member_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $cols = [
        'member_number'     => 'VARCHAR(20) NULL',
        'joined_date'       => 'DATE NULL',
        'dues_paid'         => 'TINYINT(1) NOT NULL DEFAULT 0',
        'dues_paid_date'    => 'DATE NULL',
        'dues_valid_until'  => 'DATE NULL',
        'dues_amount'       => 'DECIMAL(8,2) NULL',
        'dues_method'       => 'VARCHAR(30) NULL',
        'exempt'            => 'TINYINT(1) NOT NULL DEFAULT 0',
        'exempt_reason'     => 'TEXT NULL',
        'is_volunteer'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'relation_type'     => 'VARCHAR(20) NULL',
        'related_member_id' => 'INT UNSIGNED NULL',
        // Compliance — cerute la raportarea către comună (folkeoplysningsloven,
        // ex. Københavns Kommune: medlemsliste cu navn, adresă, fødselsdato,
        // plus tilskud suplimentar pentru medlemmer med handicap).
        'birth_date'          => 'DATE NULL',
        'gender'              => 'VARCHAR(20) NULL',
        'address'             => 'VARCHAR(255) NULL',
        'postal_code'         => 'VARCHAR(10) NULL',
        'special_needs'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'special_needs_note'  => 'TEXT NULL',
        // Al doilea email — mulți membri ai bestyrelsen/consiliului se
        // înscriu cu emailul personal, dar folosesc un email profesional
        // (@foreningenfrontdoor.dk). Opțional; dacă e completat, devine
        // AUTOMAT adresa principală pentru orice comunicare (welcome,
        // resetare parolă, emailuri din members.php) și pentru login în
        // panoul admin — nu e o alegere manuală (vezi member_primary_email()).
        'email_secondary'     => 'VARCHAR(190) NULL',
        'login_email_source'  => "ENUM('primary','secondary') NOT NULL DEFAULT 'primary'",
        // Fotografie de profil — aceeași idee ca avatarul din Setări → Profil
        // meu (bf_users.avatar), dar pentru profilul de membru.
        'avatar'              => 'VARCHAR(255) NULL',
    ];
    foreach ($cols as $name => $def) {
        try { $pdo->exec("ALTER TABLE membership_requests ADD COLUMN $name $def"); } catch (PDOException $e) {}
    }

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `member_projects` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `member_id`  INT UNSIGNED NOT NULL,
        `project_id` INT UNSIGNED NOT NULL,
        `status`     ENUM('activ','finalizat') NOT NULL DEFAULT 'activ',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_member_project` (`member_id`,`project_id`),
        KEY `idx_project` (`project_id`),
        FOREIGN KEY (`member_id`)  REFERENCES `membership_requests`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    $done = true;
}

/* =============================================================
   SISTEM VOT LIVE ADUNARE GENERALĂ — bucket de subiecte, coduri de
   vot unice per ședință, tally anonim, convocare + email final.

   Design:
   - bf_proposals capătă coloane pentru "bucket-ul" ședinței (subiectele
     alese să fie votate în ziua aceea, cu ordine/timp/moderator/tip de
     majoritate) și starea votului live (closed → open → ended).
   - bf_vote_codes: un cod unic per membru per ședință (fără parolă/cont).
   - bf_vote_participation: (proposal_id, code) — doar ca să blocăm votul
     dublu pe același subiect. NU reține opțiunea votată.
   - bf_vote_ballots: (proposal_id, option) — voturile propriu-zise, fără
     nicio coloană care să lege un vot de un membru sau de un cod. Cele
     două tabele nu sunt legate între ele — asta e mecanismul de
     anonimitate (practică, nu criptografică, dar suficientă pentru o
     asociație mică).
   ============================================================= */
function ensure_meeting_bucket_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $meeting_cols = [
        'online_link'         => 'VARCHAR(255) NULL',
        'start_time'          => 'VARCHAR(5) NULL',
        'proposal_deadline'   => 'DATETIME NULL',
        'proposals_email'     => 'VARCHAR(190) NULL',
        'date_confirmed'      => 'TINYINT(1) NOT NULL DEFAULT 0',
        'agenda_locked'       => 'TINYINT(1) NOT NULL DEFAULT 0',
        'convocation_sent_at' => 'DATETIME NULL',
        'final_sent_at'       => 'DATETIME NULL',
    ];
    foreach ($meeting_cols as $name => $def) {
        try { $pdo->exec("ALTER TABLE bf_meetings ADD COLUMN $name $def"); } catch (PDOException $e) {}
    }

    $proposal_cols = [
        'in_bucket'          => 'TINYINT(1) NOT NULL DEFAULT 0',
        'bucket_order'       => 'INT NOT NULL DEFAULT 0',
        'majority_type'      => "ENUM('simplu','doua_treimi') NOT NULL DEFAULT 'simplu'",
        'est_minutes'        => 'INT NOT NULL DEFAULT 5',
        'moderator_user_id'  => 'INT UNSIGNED NULL',
        'vote_state'         => "ENUM('closed','open','ended') NOT NULL DEFAULT 'closed'",
        'vote_opened_at'     => 'DATETIME NULL',
        'vote_ended_at'      => 'DATETIME NULL',
        // Traducere automată a subiectului — vezi ensure_membership_lang_column()
        // și translate_proposal_fields() mai jos. orig_lang ține minte în ce
        // limbă a fost scrisă propunerea inițial (aia nu se retraduce, e sursa).
        'orig_lang'          => "VARCHAR(2) NOT NULL DEFAULT 'ro'",
        'title_ro'           => 'VARCHAR(255) NULL',
        'title_da'           => 'VARCHAR(255) NULL',
        'title_en'           => 'VARCHAR(255) NULL',
        'description_ro'     => 'TEXT NULL',
        'description_da'     => 'TEXT NULL',
        'description_en'     => 'TEXT NULL',
        // Bifat quand un admin a corectat manual o traducere — la următoarea
        // salvare, acea limbă NU mai e suprascrisă automat de traducător.
        'translations_locked'=> 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    foreach ($proposal_cols as $name => $def) {
        try { $pdo->exec("ALTER TABLE bf_proposals ADD COLUMN $name $def"); } catch (PDOException $e) {}
    }

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_vote_codes` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `meeting_id`  INT UNSIGNED NOT NULL,
        `member_id`   INT UNSIGNED NULL,
        `member_name` VARCHAR(190) NULL,
        `code`        VARCHAR(16) NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_code` (`code`),
        KEY `idx_meeting` (`meeting_id`), KEY `idx_member` (`meeting_id`,`member_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_vote_participation` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `proposal_id` INT UNSIGNED NOT NULL,
        `code`        VARCHAR(16) NOT NULL,
        `voted_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_vote_once` (`proposal_id`,`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_vote_ballots` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `proposal_id` INT UNSIGNED NOT NULL,
        `option`      ENUM('da','nu','abtinere') NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), KEY `idx_proposal` (`proposal_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    // Vot NOMINAL pentru ședințele de consiliu (bestyrelsesmøde) — spre
    // deosebire de bf_vote_ballots (anonim, pentru adunarea generală), aici
    // se știe exact cine a votat ce, ca la orice proces verbal de bestyrelse.
    // Poate fi schimbat cât timp subiectul e „open" — se blochează la „ended".
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_council_votes` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `proposal_id` INT UNSIGNED NOT NULL,
        `user_id`     INT UNSIGNED NOT NULL,
        `option`      ENUM('da','nu','abtinere') NOT NULL,
        `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_council_vote` (`proposal_id`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    // Cine dintre cei (până la 5) consilieri are drept de vot LA ACEASTĂ
    // ședință de consiliu — se decide la începutul fiecărei ședințe, nu e
    // fix. Președinte/vicepreședinte/trezorier votează mereu (nu apar aici).
    // Revizorul nu apare niciodată aici — participă doar ca observator.
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_council_voters` (
        `meeting_id` INT UNSIGNED NOT NULL,
        `user_id`    INT UNSIGNED NOT NULL,
        PRIMARY KEY (`meeting_id`,`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}

    $done = true;
}

/**
 * Referatul (procesul verbal) unei ședințe — un singur referat per ședință,
 * scris într-o singură limbă (nu e conținut public trilingv, e document
 * intern oficial). Corpul narativ e liber; agenda + rezultatul votului se
 * randează automat la generarea PDF-ului, nu se stochează în content.
 * document_id/pdf_path leagă referatul de rândul creat în `documents`
 * (is_public=0) odată ce a fost generat cel puțin o dată.
 */
function ensure_referat_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS `bf_referate` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `meeting_id`   INT UNSIGNED NOT NULL,
        `lang`         VARCHAR(2) NOT NULL DEFAULT 'da',
        `content`      TEXT NULL,
        `status`       ENUM('draft','final') NOT NULL DEFAULT 'draft',
        `document_id`  INT UNSIGNED NULL,
        `pdf_path`     VARCHAR(255) NULL,
        `created_by`   INT UNSIGNED NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME NULL,
        `finalized_at` DATETIME NULL,
        PRIMARY KEY (`id`), UNIQUE KEY `uq_referat_meeting` (`meeting_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (PDOException $e) {}
    $done = true;
}

/** Referatul existent al unei ședințe (sau null dacă nu s-a creat încă unul). */
function get_referat(PDO $pdo, int $meeting_id): ?array {
    ensure_referat_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM bf_referate WHERE meeting_id=?');
    $stmt->execute([$meeting_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Rezultatul înregistrat al votului pentru fiecare subiect din bucket —
 * folosit atât pentru previzualizarea din referat-edit.php, cât și pentru
 * anexa din PDF. O singură sursă de adevăr, ca să nu diverge de ce se vede
 * în panoul de "Vot live" din meeting-bucket.php.
 */
function meeting_vote_annex(PDO $pdo, array $items, bool $is_council): array {
    $out = [];
    foreach ($items as $it) {
        $entry = ['item' => $it];
        if ($is_council) {
            $ct = council_tally($pdo, (int)$it['id']);
            $entry['tally']  = $ct['tally'];
            $entry['votes']  = $ct['votes'];
            $entry['president_option'] = $ct['president_option'];
            $entry['result'] = council_result($ct['tally'], $ct['president_option']);
            $entry['is_tie'] = ($ct['tally']['da'] === $ct['tally']['nu']) && ($ct['tally']['da'] + $ct['tally']['nu'] > 0);
        } else {
            $t_stmt = $pdo->prepare("SELECT option, COUNT(*) c FROM bf_vote_ballots WHERE proposal_id=? GROUP BY option");
            $t_stmt->execute([$it['id']]);
            $tally = ['da'=>0,'nu'=>0,'abtinere'=>0];
            foreach ($t_stmt->fetchAll() as $row) { $tally[$row['option']] = (int)$row['c']; }
            $entry['tally']  = $tally;
            $entry['result'] = majority_passed($tally['da'], $tally['nu'], $it['majority_type']);
        }
        $out[] = $entry;
    }
    return $out;
}

/**
 * Construiește HTML-ul complet al referatului (narativ + agendă + anexă vot)
 * pentru randare atât în previzualizarea din admin, cât și în PDF-ul generat
 * de dompdf — o singură sursă, ca să nu diverge preview-ul de PDF-ul final.
 */
function build_referat_html(array $meeting, array $referat, array $annex, bool $is_council, string $lang): string {
    $t = fn($k) => t($k, $lang);
    $esc = fn($s) => e($s);
    $css = '
        body{font-family:"DejaVu Sans",sans-serif;color:#161616;font-size:12.5px;line-height:1.55}
        h1{font-size:20px;margin:0 0 4px;font-weight:700}
        h2{font-size:14px;margin:26px 0 10px;padding-bottom:4px;border-bottom:1.5px solid #161616;text-transform:uppercase;letter-spacing:.04em}
        .meta{font-size:11.5px;color:#444;margin-bottom:18px;line-height:1.7}
        .narrative{white-space:pre-wrap;margin-bottom:6px}
        table{width:100%;border-collapse:collapse;margin-top:6px}
        th{text-align:left;padding:6px 7px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;color:#555;border-bottom:1.5px solid #161616}
        td{padding:7px 7px;border-bottom:1px solid #ccc;vertical-align:top}
        .maj{font-size:10px;color:#777}
        .result-passed{font-weight:700;color:#1c6b1c}
        .result-rejected{font-weight:700;color:#a41c1c}
        .result-pending{font-weight:700;color:#777}
        .votebox{margin:10px 0 18px;padding:10px 12px;border:1px solid #ccc}
        .votebox h3{font-size:12.5px;margin:0 0 6px}
        .tally{font-size:11.5px;margin-bottom:6px}
        .rollcall{font-size:10.5px;color:#444}
        .footer{margin-top:26px;font-size:9.5px;color:#888}
    ';
    $html  = '<html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
    $html .= '<h1>' . $esc($t('referat_h1')) . ' — ' . $esc($meeting['title']) . '</h1>';
    $html .= '<div class="meta">';
    if ($meeting['date']) $html .= $esc($t('export_md_date_label')) . ': ' . $esc(date('d.m.Y', strtotime($meeting['date']))) . ($meeting['start_time'] ? ', ' . $esc($meeting['start_time']) : '') . '<br>';
    if ($meeting['location']) $html .= $esc($t('export_md_location_label')) . ': ' . $esc($meeting['location']) . '<br>';
    $html .= '</div>';

    if (!empty($referat['content'])) {
        $html .= '<div class="narrative">' . $esc($referat['content']) . '</div>';
    }

    $agenda_items = agenda_with_times(array_map(fn($a) => $a['item'], $annex), $meeting['start_time'] ?? null);
    if ($agenda_items) {
        $html .= '<h2>' . $esc($t('referat_agenda_preview_h')) . '</h2><table><tr>'
               . '<th>#</th><th>' . $esc($t('export_md_agenda_col_topic')) . '</th>'
               . '<th>' . $esc($t('export_md_agenda_col_time')) . '</th>'
               . '<th>' . $esc($t('export_md_agenda_col_moderator')) . '</th></tr>';
        $i = 1;
        foreach ($agenda_items as $it) {
            $maj = $it['majority_type'] === 'doua_treimi' ? $t('majority_2_3') : $t('majority_simple');
            $html .= '<tr><td>' . $i . '</td><td>' . $esc(proposal_title($it, $lang)) . '<br><span class="maj">' . $esc($maj) . '</span></td>'
                   . '<td>' . $esc($it['time_slot'] ?? '—') . ' · ' . (int)$it['est_minutes'] . ' min</td>'
                   . '<td>' . $esc($it['moderator_name'] ?? '—') . '</td></tr>';
            $i++;
        }
        $html .= '</table>';
    }

    if ($annex) {
        $html .= '<h2>' . $esc($t('referat_votes_annex_h')) . '</h2>';
        foreach ($annex as $entry) {
            $it = $entry['item'];
            $html .= '<div class="votebox"><h3>' . $esc(proposal_title($it, $lang)) . '</h3>';
            $html .= '<div class="tally">' . $esc($t('votes_da')) . ': <strong>' . $entry['tally']['da'] . '</strong> &nbsp; '
                   . $esc($t('votes_nu')) . ': <strong>' . $entry['tally']['nu'] . '</strong> &nbsp; '
                   . $esc($t('votes_abtinere')) . ': <strong>' . $entry['tally']['abtinere'] . '</strong></div>';
            if ($is_council && !empty($entry['votes'])) {
                $html .= '<div class="rollcall"><strong>' . $esc($t('referat_council_vote_detail_h')) . ':</strong> ';
                $parts = [];
                foreach ($entry['votes'] as $v) { $parts[] = $esc($v['name']) . ': ' . $esc($t('votes_' . $v['option'])); }
                $html .= implode(' · ', $parts) . '</div>';
                if (!empty($entry['is_tie'])) {
                    $html .= '<div class="rollcall">' . $esc($t('referat_tie_president_label')) . '</div>';
                }
            }
            $result = $entry['result'];
            $cls = $result === true ? 'result-passed' : ($result === false ? 'result-rejected' : 'result-pending');
            $label = $result === null ? $t('result_pending') : ($result ? $t('result_passed') : $t('result_rejected'));
            $html .= '<div class="' . $cls . '">' . $esc($label) . '</div></div>';
        }
    }

    $html .= '<p class="footer">Foreningen Front Door · foreningenfrontdoor.dk · ' . e(date('d.m.Y H:i')) . '</p>';
    $html .= '</body></html>';
    return $html;
}

/**
 * Generează PDF-ul referatului cu dompdf (vendor/ livrat separat — vezi
 * README-deploy). Aruncă Exception dacă vendor/autoload.php lipsește sau
 * dacă generarea eșuează, ca apelantul să poată arăta o eroare clară.
 */
function render_referat_pdf(string $html): string {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new Exception('Librăria PDF (vendor/dompdf) nu e instalată pe server. Încarcă folderul vendor/ livrat separat.');
    }
    require_once $autoload;

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    // fontDir/fontCache rămân pe default (vendor/dompdf/dompdf/lib/fonts, unde
    // sunt deja fonturile DejaVu livrate) — nu au nevoie de scriere la runtime.
    $options->set('tempDir', sys_get_temp_dir());
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * Limba preferată a unui membru (nu a unui cont de panel) — folosită ca să
 * primească emailurile (convocare, agendă finală) și subiectele de vot în
 * limba lui, nu în limba celui din conducere care apasă „Trimite”. Presetată
 * automat din formularul public de înscriere (join-submit.php trimite deja
 * limba paginii), corectabilă manual din profilul membrului.
 */
function ensure_membership_lang_column(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE membership_requests ADD COLUMN lang VARCHAR(2) NOT NULL DEFAULT 'da'"); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Traducător automat, fără cheie/cont — motorul Google Translate (endpoint
 * public folosit de majoritatea proiectelor mici) cu MyMemory ca rezervă
 * dacă primul eșuează sau răspunde gol. Niciodată nu blochează salvarea:
 * la eșecul ambelor, întoarce textul original neschimbat.
 */
/**
 * Un singur apel către un motor de traducere, pentru text scurt (sub
 * pragul de chunking — vezi translate_text()). NU apela direct pentru
 * text arbitrar de lung: MyMemory (rezerva) respinge orice peste 500 de
 * caractere, dar o face cu HTTP 200 și un mesaj de eroare în locul
 * traducerii ("QUERY LENGTH LIMIT EXCEEDED...") — de-aia verificăm
 * explicit acest tipar mai jos, ca să nu-l salvăm drept traducere reală.
 */
function translate_chunk(string $text, string $from, string $to): string {
    $text = trim($text);
    if ($text === '' || $from === $to) return $text;

    // 1) Google Translate (neoficial, gratuit, fără cheie)
    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . urlencode($from)
         . '&tl=' . urlencode($to) . '&dt=t&q=' . urlencode($text);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FrontDoorAdmin/1.0)',
    ]);
    $resp = curl_exec($ch);
    $ok   = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    if ($ok && $resp) {
        $data = json_decode($resp, true);
        if (is_array($data) && !empty($data[0])) {
            $out = '';
            foreach ($data[0] as $seg) { if (isset($seg[0])) $out .= $seg[0]; }
            if (trim($out) !== '') return $out;
        }
    }

    // 2) MyMemory (rezervă, fără cheie) — limitat la 500 caractere per
    // cerere; peste limită răspunde tot cu 200 OK, dar cu un text de
    // eroare în loc de traducere, așa că îl recunoaștem și îl respingem.
    $url2 = 'https://api.mymemory.translated.net/get?q=' . urlencode($text) . '&langpair=' . urlencode($from) . '|' . urlencode($to);
    $ch2 = curl_init($url2);
    curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $resp2 = curl_exec($ch2);
    $ok2   = curl_getinfo($ch2, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch2);
    if ($ok2 && $resp2) {
        $data2 = json_decode($resp2, true);
        $status2 = $data2['responseStatus'] ?? 200;
        $out2 = $data2['responseData']['translatedText'] ?? '';
        $looks_like_error = stripos((string)$out2, 'QUERY LENGTH LIMIT') !== false
            || stripos((string)$out2, 'MYMEMORY WARNING') !== false
            || stripos((string)$out2, 'INVALID') !== false && stripos((string)$out2, 'LANGPAIR') !== false;
        if ((int)$status2 === 200 && !$looks_like_error && trim((string)$out2) !== '') return $out2;
    }

    return $text; // eșec total — mai bine textul original decât nimic
}

/**
 * Traduce text de orice lungime, spărgându-l în bucăți sigure pentru
 * ambele motoare (MyMemory refuză categoric peste 500 caractere) —
 * întâi pe paragrafe (linii), apoi pe propoziții dacă un paragraf tot
 * rămâne prea lung, apoi tăiere brută ca ultimă soluție. Rezultatele se
 * lipesc înapoi păstrând structura originală pe linii.
 */
function translate_text(string $text, string $from, string $to): string {
    $text = trim($text);
    if ($text === '' || $from === $to) return $text;

    $limit = 450; // marjă sub pragul real de 500 al MyMemory
    if (mb_strlen($text) <= $limit) return translate_chunk($text, $from, $to);

    $lines = explode("\n", $text);
    $out_lines = [];
    foreach ($lines as $line) {
        if (trim($line) === '') { $out_lines[] = ''; continue; }
        if (mb_strlen($line) <= $limit) {
            $out_lines[] = translate_chunk($line, $from, $to);
            continue;
        }
        // Paragraf prea lung — îl spargem pe propoziții, acumulând bucăți
        // cât mai mari sub prag (mai puține cereri = mai rapid).
        $sentences = preg_split('/(?<=[.!?])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [$line];
        $chunks = []; $buf = '';
        foreach ($sentences as $s) {
            if ($buf !== '' && mb_strlen($buf . ' ' . $s) > $limit) { $chunks[] = $buf; $buf = $s; }
            else { $buf = $buf === '' ? $s : $buf . ' ' . $s; }
            // Propoziție singură deja peste prag — tăiere brută.
            while (mb_strlen($buf) > $limit) {
                $chunks[] = mb_substr($buf, 0, $limit);
                $buf = mb_substr($buf, $limit);
            }
        }
        if ($buf !== '') $chunks[] = $buf;

        $translated = array_map(fn($c) => translate_chunk($c, $from, $to), $chunks);
        $out_lines[] = implode(' ', $translated);
    }
    return implode("\n", $out_lines);
}

/**
 * Traduce titlul+descrierea unei propuneri din limba originală în
 * celelalte două. Întoarce toate cele 6 câmpuri, gata de INSERT/UPDATE.
 * $keep = limbile pe care NU le retraducem (deja corectate manual).
 */
function translate_proposal_fields(string $title, string $description, string $orig_lang, array $existing = [], array $keep = []): array {
    $langs = ['ro','da','en'];
    $out = ['orig_lang' => $orig_lang];
    foreach ($langs as $l) {
        if ($l === $orig_lang) {
            $out['title_' . $l] = $title;
            $out['description_' . $l] = $description;
        } elseif (in_array($l, $keep, true) && isset($existing['title_' . $l])) {
            $out['title_' . $l] = $existing['title_' . $l];
            $out['description_' . $l] = $existing['description_' . $l] ?? '';
        } else {
            $out['title_' . $l] = translate_text($title, $orig_lang, $l);
            $out['description_' . $l] = $description !== '' ? translate_text($description, $orig_lang, $l) : '';
        }
    }
    return $out;
}

/** Titlul unei propuneri în limba cerută, cu fallback pe coloana veche „title”. */
function proposal_title(array $p, ?string $lang = null): string {
    $lang = $lang ?? ui_lang();
    return $p['title_' . $lang] ?: ($p['title'] ?? '');
}

/** Descrierea unei propuneri în limba cerută, cu fallback pe coloana veche „description”. */
function proposal_description(array $p, ?string $lang = null): string {
    $lang = $lang ?? ui_lang();
    return $p['description_' . $lang] ?: ($p['description'] ?? '');
}

/**
 * Migrare leneșă pentru traducerea automată a evenimentelor — site-ul
 * public e doar RO/DA (spre deosebire de propuneri, care au și EN pentru
 * membrii cu profil în engleză), deci refolosim direct title_ro/title_da/
 * description_ro/description_da existente ca stocare finală, adăugăm doar
 * limba originală și un flag „nu retraduce automat”.
 */
function ensure_events_translation_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    // Un eveniment poate fi creat fără dată încă stabilită ("TBD") — coloana
    // trebuie să accepte NULL. MODIFY e idempotent, sigur de rulat de mai
    // multe ori (nu aruncă eroare dacă e deja NULL).
    try { $pdo->exec("ALTER TABLE events MODIFY COLUMN date DATE NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN orig_lang VARCHAR(2) NOT NULL DEFAULT 'ro'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN translations_locked TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN title_en VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN description_en TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN locked_langs VARCHAR(10) NOT NULL DEFAULT ''"); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Traduce titlul+descrierea unui eveniment din limba originală în celelalte
 * două — acum RO/DA/EN, la fel ca la propuneri (site-ul public afișează
 * fiecare eveniment în limba generală aleasă de vizitator). Pur și simplu
 * refolosește translate_proposal_fields — logica e identică, doar numele e
 * specific evenimentelor ca să fie clar la citire de unde vine apelul.
 */
function translate_event_fields(string $title, string $description, string $orig_lang, array $existing = [], array $keep = []): array {
    return translate_proposal_fields($title, $description, $orig_lang, $existing, $keep);
}

/**
 * Etichetele (tags) erau stocate într-o singură limbă (`name`). Adăugăm
 * name_ro/name_da/name_en ca să se poată traduce și pe site-ul public,
 * la fel ca titlul/descrierea evenimentelor. Coloana `name` rămâne
 * neatinsă (cod vechi o mai citește), doar e populată din name_ro.
 */
function ensure_tags_i18n_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_ro VARCHAR(60) NULL AFTER name"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_da VARCHAR(60) NULL AFTER name_ro"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE tags ADD COLUMN name_en VARCHAR(60) NULL AFTER name_da"); } catch (PDOException $e) {}
    try { $pdo->exec("UPDATE tags SET name_ro=COALESCE(name_ro,name), name_da=COALESCE(name_da,name), name_en=COALESCE(name_en,name) WHERE name_ro IS NULL OR name_da IS NULL OR name_en IS NULL"); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Traduce automat în RO/DA/EN un set arbitrar de câmpuri text (nu doar
 * title+description) — folosit pentru proiecte, unde pagina publică de
 * transparență are 3 perechi de câmpuri (headline, story, budget_breakdown).
 * $texts = ['headline' => '...', 'story' => '...', ...] în limba originală.
 * $existing/$keep — la fel ca la translate_proposal_fields: $keep e lista
 * limbilor „blocate” (editate manual), pentru care păstrăm $existing în loc
 * să retraducem.
 */
function translate_project_fields(array $texts, string $orig_lang, array $existing = [], array $keep = []): array {
    $langs = ['ro', 'da', 'en'];
    $out = ['orig_lang' => $orig_lang];
    foreach ($langs as $l) {
        foreach ($texts as $field => $value) {
            $key = $field . '_' . $l;
            if ($l === $orig_lang) {
                $out[$key] = $value;
            } elseif (in_array($l, $keep, true) && isset($existing[$key])) {
                $out[$key] = $existing[$key];
            } else {
                $out[$key] = $value !== '' ? translate_text($value, $orig_lang, $l) : '';
            }
        }
    }
    return $out;
}

/** Adaugă lazy coloanele pentru traducere trilingvă pe tabelul `projects`. */
function ensure_projects_translation_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE projects ADD COLUMN orig_lang VARCHAR(2) NOT NULL DEFAULT 'ro'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE projects ADD COLUMN locked_langs VARCHAR(10) NOT NULL DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE projects ADD COLUMN title_en VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE projects ADD COLUMN description_en TEXT NULL"); } catch (PDOException $e) {}
    $done = true;
}

/** Adaugă lazy coloanele pentru traducere trilingvă + finanțare pe `project_details` / `project_donors`. */
function ensure_project_details_translation_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE project_details ADD COLUMN orig_lang VARCHAR(2) NOT NULL DEFAULT 'ro'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_details ADD COLUMN locked_langs VARCHAR(10) NOT NULL DEFAULT ''"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_details ADD COLUMN headline_en VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_details ADD COLUMN story_en TEXT NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_details ADD COLUMN budget_breakdown_en TEXT NULL"); } catch (PDOException $e) {}
    // Finanțare (fost „donatori”) — tip de intrare + câmpuri extra pentru Grant / Linie de finanțare.
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN entry_type VARCHAR(30) NOT NULL DEFAULT 'donatie'"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN entry_type_custom VARCHAR(100) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN funder_name VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN reference_number VARCHAR(100) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN period_start DATE NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE project_donors ADD COLUMN period_end DATE NULL"); } catch (PDOException $e) {}
    $done = true;
}

/**
 * Coloane pentru postarea pe rețele sociale a unui eveniment — link către
 * Evenimentul Facebook real (creat manual, Graph API nu mai permite
 * crearea de evenimente pentru aplicații normale, doar citire) + urmele
 * postărilor automate (Pagină FB + Instagram), ca să nu postăm de 2 ori.
 */
function ensure_events_social_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try { $pdo->exec("ALTER TABLE events ADD COLUMN fb_event_url VARCHAR(255) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN fb_post_id VARCHAR(100) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN fb_posted_at DATETIME NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN ig_post_id VARCHAR(100) NULL"); } catch (PDOException $e) {}
    try { $pdo->exec("ALTER TABLE events ADD COLUMN ig_posted_at DATETIME NULL"); } catch (PDOException $e) {}
    $done = true;
}

/** Sunt completate în config.php credențialele pentru Pagina de Facebook? */
function fb_social_configured(): bool {
    return defined('FB_PAGE_ID') && FB_PAGE_ID && defined('FB_PAGE_ACCESS_TOKEN') && FB_PAGE_ACCESS_TOKEN;
}

/** Sunt completate în config.php credențialele pentru contul de Instagram Business? */
function ig_social_configured(): bool {
    return defined('IG_BUSINESS_ACCOUNT_ID') && IG_BUSINESS_ACCOUNT_ID && defined('FB_PAGE_ACCESS_TOKEN') && FB_PAGE_ACCESS_TOKEN;
}

/**
 * Postează un mesaj text pe Pagina de Facebook a asociației, prin Graph
 * API (POST /{page-id}/feed). Token-ul (Page Access Token pe termen lung)
 * vine din config.php — niciodată livrat de-aici cu o valoare reală.
 */
function fb_post_to_page(string $message): array {
    if (!fb_social_configured()) return ['ok' => false, 'error' => 'FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN lipsesc din config.php.'];
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(FB_PAGE_ID) . '/feed');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['message' => $message, 'access_token' => FB_PAGE_ACCESS_TOKEN]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    if ($code === 200 && isset($data['id'])) return ['ok' => true, 'id' => $data['id']];
    return ['ok' => false, 'error' => $data['error']['message'] ?? ($cerr ?: ('HTTP ' . $code))];
}

/**
 * Postează o imagine + descriere pe Instagram Business, prin Graph API —
 * în 2 pași (container media, apoi publish). Imaginea trebuie să fie la
 * un URL public (site-ul live), Instagram nu acceptă fișiere locale.
 */
function ig_publish_post(string $image_url, string $caption): array {
    if (!ig_social_configured()) return ['ok' => false, 'error' => 'IG_BUSINESS_ACCOUNT_ID / FB_PAGE_ACCESS_TOKEN lipsesc din config.php.'];
    $base = 'https://graph.facebook.com/v21.0/' . rawurlencode(IG_BUSINESS_ACCOUNT_ID);

    $ch = curl_init($base . '/media');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['image_url' => $image_url, 'caption' => $caption, 'access_token' => FB_PAGE_ACCESS_TOKEN]),
    ]);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    $data = json_decode((string)$resp, true);
    if ($code !== 200 || empty($data['id'])) {
        return ['ok' => false, 'error' => $data['error']['message'] ?? ($cerr ?: ('HTTP ' . $code)) . ' (creare container)'];
    }
    $container_id = $data['id'];

    $ch2 = curl_init($base . '/media_publish');
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['creation_id' => $container_id, 'access_token' => FB_PAGE_ACCESS_TOKEN]),
    ]);
    $resp2 = curl_exec($ch2); $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE); $cerr2 = curl_error($ch2); curl_close($ch2);
    $data2 = json_decode((string)$resp2, true);
    if ($code2 === 200 && isset($data2['id'])) return ['ok' => true, 'id' => $data2['id']];
    return ['ok' => false, 'error' => $data2['error']['message'] ?? ($cerr2 ?: ('HTTP ' . $code2)) . ' (publicare)'];
}

/**
 * Estimare gratuită, pe bază de reguli, a duratei unui subiect — fără AI.
 * Bază per categorie + timp suplimentar proporțional cu lungimea descrierii,
 * plafonat la un interval rezonabil pentru o ședință.
 */
function estimate_topic_minutes(string $description, string $category): int {
    $base = ['financiar'=>8,'administrativ'=>6,'proiecte'=>6,'cultural'=>5,'societate'=>5,'artistic'=>5,'altul'=>5];
    $minutes = $base[$category] ?? 5;
    $words = str_word_count(strip_tags($description ?? ''));
    $minutes += (int)floor($words / 40);
    return max(3, min(20, $minutes));
}

/** Cod de vot: fără caractere confuzabile (0/O, 1/I/L). */
function gen_vote_code(): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}

/**
 * A trecut pragul de majoritate? null = încă nedecis (fără voturi
 * decisive). Abținerile nu intră la numitor — majoritate din cei care
 * s-au exprimat Da/Nu, ca la orice adunare clasică.
 */
function majority_passed(int $da, int $nu, string $type): ?bool {
    if ($da + $nu === 0) return null;
    $ratio = $da / ($da + $nu);
    return $type === 'doua_treimi' ? ($ratio > (2/3)) : ($ratio > 0.5);
}

/**
 * Tally + detaliu nominal pentru votul de consiliu al unui subiect —
 * cine a votat ce, plus totalurile. La egalitate (§8.4 din statut),
 * votul președintelui e cel care decide, nu „respins" implicit.
 */
function council_tally(PDO $pdo, int $proposal_id): array {
    $stmt = $pdo->prepare(
        'SELECT v.option, v.user_id, u.name, u.position FROM bf_council_votes v
         JOIN bf_users u ON u.id = v.user_id WHERE v.proposal_id=? ORDER BY u.name ASC'
    );
    $stmt->execute([$proposal_id]);
    $rows = $stmt->fetchAll();
    $tally = ['da' => 0, 'nu' => 0, 'abtinere' => 0];
    $president_option = null;
    foreach ($rows as $r) {
        $tally[$r['option']]++;
        if ($r['position'] === 'presedinte') $president_option = $r['option'];
    }
    return ['tally' => $tally, 'votes' => $rows, 'president_option' => $president_option];
}

/**
 * Rezultatul unui subiect de consiliu, ținând cont de decizia
 * președintelui la egalitate (§8.4: „ved stemmelighed er formandens
 * stemme afgørende"). null = fără voturi decisive încă.
 */
function council_result(array $tally, ?string $president_option): ?bool {
    $da = $tally['da']; $nu = $tally['nu'];
    if ($da + $nu === 0) return null;
    if ($da === $nu) return $president_option === 'da' ? true : ($president_option === 'nu' ? false : null);
    return $da > $nu;
}

/**
 * Membrii activi cu email valid, dedup pe emailul principal — fiecare cu
 * limba lui preferată (lang), ca fiecare email/agendă să iasă în limba
 * profilului, nu în limba celui din conducere care apasă „Trimite”.
 */
function assembly_active_recipients(PDO $pdo): array {
    ensure_membership_lang_column($pdo);
    $rows = $pdo->query("SELECT id, name, email, email_secondary, lang FROM membership_requests WHERE status='active'")->fetchAll();
    $out = []; $seen = [];
    foreach ($rows as $r) {
        $email = member_primary_email($r);
        if (!$email || isset($seen[strtolower($email)])) continue;
        $seen[strtolower($email)] = true;
        $lang = in_array($r['lang'] ?? '', ['ro','da','en'], true) ? $r['lang'] : 'da';
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $email, 'lang' => $lang];
    }
    return $out;
}

// Pozițiile care chiar formează bestyrelsen conform statutului — NU include
// revizorul, care conform §10.3 din vedtægter nu poate fi membru al
// bestyrelsen ("Revisor må ikke være medlem af bestyrelsen"). Consilierii
// sunt până la 5 (MAX_CONSILIERI); ei sunt convocați ca oricare membru al
// bestyrelsen, dar dreptul lor de vot la o anume ședință de consiliu se
// decide separat, per ședință (vezi bf_council_voters / council_can_vote).
const BOARD_POSITIONS = ['presedinte', 'vicepresedinte', 'trezorier', 'consilier'];

// Poziții cu drept de vot PERMANENT la orice ședință de consiliu — votează
// la fiecare subiect, indiferent de ședință.
const COUNCIL_PERMANENT_VOTERS = ['presedinte', 'vicepresedinte', 'trezorier'];

/** Membrii activi ai bestyrelsen (fără revizor) — pentru ședințe de consiliu. */
function board_members(PDO $pdo): array {
    $ph = implode(',', array_fill(0, count(BOARD_POSITIONS), '?'));
    $stmt = $pdo->prepare("SELECT id, name, email, position, ui_lang FROM bf_users WHERE active=1 AND position IN ($ph) ORDER BY id ASC");
    $stmt->execute(BOARD_POSITIONS);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $lang = in_array($r['ui_lang'] ?? '', ['ro','da','en'], true) ? $r['ui_lang'] : 'ro';
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'lang' => $lang, 'position' => $r['position']];
    }
    return $out;
}

/**
 * Revizorul activ — participă la ședințele de consiliu doar ca observator,
 * pentru transparență (§10.3: nu e membru al bestyrelsen, deci nu votează).
 */
function revisor_members(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, name, email, position, ui_lang FROM bf_users WHERE active=1 AND position='revizor' ORDER BY id ASC");
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $lang = in_array($r['ui_lang'] ?? '', ['ro','da','en'], true) ? $r['ui_lang'] : 'ro';
        $out[] = ['id' => (int)$r['id'], 'name' => $r['name'], 'email' => $r['email'], 'lang' => $lang, 'position' => $r['position']];
    }
    return $out;
}

/** E poziția curentă parte din bestyrelse (nu doar are acces la panel)? */
function is_board_position(array $user): bool {
    return in_array($user['position'] ?? '', BOARD_POSITIONS, true);
}

/** ID-urile consilierilor desemnați ca votanți la această ședință de consiliu. */
function council_voter_ids(PDO $pdo, int $meeting_id): array {
    $stmt = $pdo->prepare('SELECT user_id FROM bf_council_voters WHERE meeting_id=?');
    $stmt->execute([$meeting_id]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Salvează (înlocuind lista anterioară) consilierii-votanți ai unei ședințe de consiliu. */
function set_council_voters(PDO $pdo, int $meeting_id, array $user_ids): void {
    $pdo->prepare('DELETE FROM bf_council_voters WHERE meeting_id=?')->execute([$meeting_id]);
    $stmt = $pdo->prepare('INSERT INTO bf_council_voters (meeting_id, user_id) VALUES (?,?)');
    foreach (array_unique(array_map('intval', $user_ids)) as $uid) {
        if ($uid > 0) $stmt->execute([$meeting_id, $uid]);
    }
}

/**
 * Poate acest utilizator vota la această ședință de consiliu?
 * Președinte/vicepreședinte/trezorier — mereu (COUNCIL_PERMANENT_VOTERS).
 * Consilier — doar dacă a fost desemnat votant pentru ACEASTĂ ședință
 * (se decide la începutul fiecărei ședințe, nu e fix — până la 5 posibili,
 * dar pot fi și mai puțini după caz). Revizor — niciodată (doar transparență).
 */
function council_can_vote(PDO $pdo, int $meeting_id, array $user): bool {
    $pos = $user['position'] ?? '';
    if (in_array($pos, COUNCIL_PERMANENT_VOTERS, true)) return true;
    if ($pos !== 'consilier') return false;
    $stmt = $pdo->prepare('SELECT 1 FROM bf_council_voters WHERE meeting_id=? AND user_id=?');
    $stmt->execute([$meeting_id, (int)($user['id'] ?? 0)]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Destinatarii unei convocări — membrii generali pentru generalforsamling
 * / extraordinară; pentru ședință de consiliu, toată bestyrelsen (inclusiv
 * consilierii, indiferent dacă votează sau nu la ședința respectivă) PLUS
 * revizorul, convocat ca observator pentru transparență.
 */
function meeting_recipients(PDO $pdo, array $meeting): array {
    return ($meeting['type'] ?? 'generalforsamling') === 'consiliu'
        ? array_merge(board_members($pdo), revisor_members($pdo))
        : assembly_active_recipients($pdo);
}

/** Subiectele din bucket-ul unei ședințe, în ordinea de pe agendă. */
function bucket_items(PDO $pdo, int $meeting_id): array {
    $stmt = $pdo->prepare(
        'SELECT p.*, u.name AS moderator_name
         FROM bf_proposals p LEFT JOIN bf_users u ON u.id = p.moderator_user_id
         WHERE p.meeting_id = ? AND p.in_bucket = 1
         ORDER BY p.bucket_order ASC, p.id ASC'
    );
    $stmt->execute([$meeting_id]);
    return $stmt->fetchAll();
}

/** Adaugă intervalul orar cumulat (dacă ședința are oră de start) pe fiecare item. */
function agenda_with_times(array $items, ?string $start_time): array {
    $t = $start_time ? DateTime::createFromFormat('H:i', $start_time) : null;
    foreach ($items as &$it) {
        if ($t) {
            $it['time_slot'] = $t->format('H:i');
            $t->modify('+' . max(1,(int)$it['est_minutes']) . ' minutes');
        } else {
            $it['time_slot'] = null;
        }
    }
    unset($it);
    return $items;
}

/**
 * Randează varianta HTML a agendei (folosită atât în emailuri, cât și pe
 * pagina printabilă) — o singură sursă de adevăr pentru "cum arată
 * ordinea de zi", ca să nu diverge conținutul convocării de cel afișat.
 */
function render_agenda_html(array $meeting, array $items, bool $final, ?string $lang = null): string {
    $lang = $lang ?? ui_lang();
    $items = agenda_with_times($items, $meeting['start_time'] ?? null);
    $html  = '<table style="width:100%;border-collapse:collapse;font-family:Jost,sans-serif">';
    $html .= '<tr><th style="text-align:left;padding:8px;border-bottom:2px solid #000;font-size:12px">#</th>'
           . '<th style="text-align:left;padding:8px;border-bottom:2px solid #000;font-size:12px">' . e(t('export_md_agenda_col_topic', $lang)) . '</th>'
           . '<th style="text-align:left;padding:8px;border-bottom:2px solid #000;font-size:12px">' . e(t('export_md_agenda_col_time', $lang)) . '</th>'
           . '<th style="text-align:left;padding:8px;border-bottom:2px solid #000;font-size:12px">' . e(t('export_md_agenda_col_moderator', $lang)) . '</th></tr>';
    $i = 1;
    foreach ($items as $it) {
        $maj = $it['majority_type'] === 'doua_treimi' ? t('majority_2_3_short', $lang) : t('majority_simple_short', $lang);
        $html .= '<tr><td style="padding:8px;border-bottom:1px solid #ddd">' . $i . '</td>'
               . '<td style="padding:8px;border-bottom:1px solid #ddd">' . e(proposal_title($it, $lang)) . ' <span style="color:#888;font-size:11px">(' . e($maj) . ')</span></td>'
               . '<td style="padding:8px;border-bottom:1px solid #ddd">' . e($it['time_slot'] ?? '—') . ' · ' . (int)$it['est_minutes'] . ' min</td>'
               . '<td style="padding:8px;border-bottom:1px solid #ddd">' . e($it['moderator_name'] ?? '—') . '</td></tr>';
        $i++;
    }
    $html .= '</table>';
    if (!$final) $html = '<p style="font-size:12px;color:#888;margin-bottom:10px">' . e(t('agenda_draft_notice', $lang)) . '</p>' . $html;
    return $html;
}

/**
 * Trimite convocarea inițială (dată/loc/link online, ordinea de zi draft,
 * adresa pentru propuneri, termenul limită) la toți membrii activi.
 * Returnează [trimise, eșuate=>[nume=>eroare]].
 */
function send_convocation_email_to_all(PDO $pdo, array $meeting): array {
    $recipients = meeting_recipients($pdo, $meeting);
    $items = bucket_items($pdo, (int)$meeting['id']);
    $when = $meeting['date'] ? date('d.m.Y', strtotime($meeting['date'])) : '—';
    if (!empty($meeting['start_time'])) $when .= ', ' . $meeting['start_time'];
    $deadline = !empty($meeting['proposal_deadline']) ? date('d.m.Y H:i', strtotime($meeting['proposal_deadline'])) : '—';
    $prop_email = $meeting['proposals_email'] ?: 'office@foreningenfrontdoor.dk';

    $sent = 0; $failed = [];
    foreach ($recipients as $r) {
        $lang = $r['lang'];
        $agenda_html = render_agenda_html($meeting, $items, false, $lang);
        $body  = '<p>' . e(t('email_hello_prefix', $lang)) . ' ' . e($r['name']) . ',</p>';
        $body .= '<p>' . e(t('convocation_intro', $lang)) . '</p>';
        $body .= '<p><strong>' . e(t('export_md_date_label', $lang)) . ':</strong> ' . e($when) . '<br>';
        $body .= '<strong>' . e(t('export_md_location_label', $lang)) . ':</strong> ' . e($meeting['location'] ?: '—') . '<br>';
        if (!empty($meeting['online_link'])) $body .= '<strong>' . e(t('online_link_label', $lang)) . ':</strong> <a href="' . e($meeting['online_link']) . '">' . e($meeting['online_link']) . '</a><br>';
        $body .= '</p>';
        $body .= '<p><strong>' . e(t('agenda_draft_h', $lang)) . '</strong></p>' . $agenda_html;
        $body .= '<p style="margin-top:16px">' . e(t('proposals_intro', $lang)) . ' <a href="mailto:' . e($prop_email) . '">' . e($prop_email) . '</a>. ';
        $body .= e(t('proposals_deadline_prefix', $lang)) . ' <strong>' . e($deadline) . '</strong>.</p>';
        $body .= '<p style="margin-top:16px;color:#666;font-size:12px">Foreningen Front Door · foreningenfrontdoor.dk</p>';

        $subject = t('convocation_subject_prefix', $lang) . ' ' . $meeting['title'];
        $r_ok = send_smtp_mail($r['email'], $r['name'], $subject, $body, true);
        if ($r_ok === true) $sent++; else $failed[$r['name']] = $r_ok;
    }
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Trimite emailul final (agenda închisă/finală + cod unic de vot per
 * membru + link către pagina de vot) la toți membrii activi. Generează
 * codurile care lipsesc pe loc.
 */
function send_final_email_to_all(PDO $pdo, array $meeting): array {
    $is_council = ($meeting['type'] ?? 'generalforsamling') === 'consiliu';
    $recipients = meeting_recipients($pdo, $meeting);
    $items = bucket_items($pdo, (int)$meeting['id']);
    $when = $meeting['date'] ? date('d.m.Y', strtotime($meeting['date'])) : '—';
    if (!empty($meeting['start_time'])) $when .= ', ' . $meeting['start_time'];
    $base_url = 'https://foreningenfrontdoor.dk/admin/vote.php?cod=';
    $bucket_url = 'https://foreningenfrontdoor.dk/admin/meeting-bucket.php?id=' . (int)$meeting['id'];

    $sent = 0; $failed = [];
    foreach ($recipients as $r) {
        $lang = $r['lang'];
        $code = null;
        if (!$is_council) {
            // Vot anonim (generalforsamling) — cod unic per membru per ședință.
            $stmt = $pdo->prepare('SELECT code FROM bf_vote_codes WHERE meeting_id=? AND member_id=? LIMIT 1');
            $stmt->execute([$meeting['id'], $r['id']]);
            $code = $stmt->fetchColumn();
            if (!$code) {
                do { $code = gen_vote_code(); $dup = $pdo->prepare('SELECT 1 FROM bf_vote_codes WHERE code=?'); $dup->execute([$code]); } while ($dup->fetch());
                $pdo->prepare('INSERT INTO bf_vote_codes (meeting_id,member_id,member_name,code) VALUES (?,?,?,?)')
                    ->execute([$meeting['id'], $r['id'], $r['name'], $code]);
            }
        }

        $agenda_html = render_agenda_html($meeting, $items, true, $lang);
        $body  = '<p>' . e(t('email_hello_prefix', $lang)) . ' ' . e($r['name']) . ',</p>';
        $body .= '<p>' . e(t('final_email_intro', $lang)) . '</p>';
        $body .= '<p><strong>' . e(t('export_md_date_label', $lang)) . ':</strong> ' . e($when) . '<br>';
        $body .= '<strong>' . e(t('export_md_location_label', $lang)) . ':</strong> ' . e($meeting['location'] ?: '—') . '<br>';
        if (!empty($meeting['online_link'])) $body .= '<strong>' . e(t('online_link_label', $lang)) . ':</strong> <a href="' . e($meeting['online_link']) . '">' . e($meeting['online_link']) . '</a><br>';
        $body .= '</p>';
        $body .= '<p style="color:#a00;font-weight:600">' . e(t('proposals_closed_notice', $lang)) . '</p>';
        $body .= '<p><strong>' . e(t('agenda_final_h', $lang)) . '</strong></p>' . $agenda_html;
        if ($is_council) {
            $body .= '<p style="margin-top:16px">' . e(t('council_vote_from_panel_notice', $lang)) . ' <a href="' . e($bucket_url) . '">' . e($bucket_url) . '</a></p>';
        } else {
            $body .= '<div style="margin-top:20px;padding:16px;border:2px solid #000;text-align:center">';
            $body .= '<div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#666">' . e(t('your_vote_code_label', $lang)) . '</div>';
            $body .= '<div style="font-size:26px;font-weight:900;letter-spacing:.08em;margin:6px 0">' . e($code) . '</div>';
            $body .= '<a href="' . e($base_url . $code) . '" style="font-size:13px">' . e($base_url . $code) . '</a>';
            $body .= '</div>';
        }
        $body .= '<p style="margin-top:16px;color:#666;font-size:12px">Foreningen Front Door · foreningenfrontdoor.dk</p>';

        $subject = t('final_subject_prefix', $lang) . ' ' . $meeting['title'];
        $r_ok = send_smtp_mail($r['email'], $r['name'], $subject, $body, true);
        if ($r_ok === true) $sent++; else $failed[$r['name']] = $r_ok;
    }
    return ['sent' => $sent, 'failed' => $failed];
}

define('DUES_METHODS', [
    'numerar'  => 'Numerar',
    'transfer' => 'Transfer bancar',
    'mobilepay'=> 'MobilePay',
    'altul'    => 'Altul',
]);

define('RELATION_TYPES', [
    ''         => '— Fără relație —',
    'parinte'  => 'Este părintele lui',
    'copil'    => 'Este copilul lui',
]);

define('GENDERS', [
    'f'      => 'Femeie',
    'm'      => 'Bărbat',
    'altul'  => 'Altul / nu dorește să declare',
]);

/**
 * Vârsta la data de 31 decembrie a anului curent — comuna (ex. Københavns
 * Kommune) calculează grupele de vârstă pentru tilskud după vârsta membrului
 * la 31.12, nu după vârsta de azi.
 */
function age_on_dec31(?string $birthDate): ?int {
    if (!$birthDate) return null;
    try {
        $dob = new DateTime($birthDate);
        $ref = new DateTime(date('Y') . '-12-31');
        return $dob > $ref ? null : $dob->diff($ref)->y;
    } catch (Exception $e) {
        return null;
    }
}

function flash(string $type, string $msg): void {
    $_SESSION['fd_flash'] = ['type' => $type, 'msg' => $msg];
}

function get_flash(): ?array {
    $f = $_SESSION['fd_flash'] ?? null;
    unset($_SESSION['fd_flash']);
    return $f;
}

// Câte conturi active pot exista deodată în panoul admin — bestyrelsen
// (președinte, vicepreședinte, trezorier) + consilieri + revizor.
define('MAX_PANEL_ACCOUNTS', 9);

define('POSITIONS', [
    'presedinte'     => ['label' => 'Președinte',    'role' => 'admin'],
    'vicepresedinte' => ['label' => 'Vicepreședinte','role' => 'admin'],
    'trezorier'      => ['label' => 'Trezorier',     'role' => 'admin'],
    'revizor'        => ['label' => 'Revizor',       'role' => 'revizor'],
    'consilier'      => ['label' => 'Consilier',     'role' => 'member'],
]);

function position_label(array $user): string {
    $pos = POSITIONS[$user['position']] ?? null;
    if (!$pos) return $user['position'] ?? '';
    if (($user['position'] ?? '') === 'consilier' && !empty($user['position_label'])) {
        return $pos['label'] . ' — ' . $user['position_label'];
    }
    return $pos['label'];
}

function layout_head(string $title, string $active_tab): void {
    $user = $_SESSION['fd_user'] ?? [];
    $tabs = [
        'events'    => ['label' => t('nav_events'),    'url' => '/admin/events.php'],
        'projects'  => ['label' => t('nav_projects'),  'url' => '/admin/projects.php'],
        'members'   => ['label' => t('nav_members'),   'url' => '/admin/members.php'],
        'topics'    => ['label' => t('nav_topics'),    'url' => '/admin/topics.php'],
        'documents' => ['label' => t('nav_documents'), 'url' => '/admin/documents.php'],
        'regnskab'  => ['label' => t('nav_regnskab'),  'url' => '/admin/regnskab.php'],
        'settings'  => ['label' => t('nav_settings'),  'url' => '/admin/settings.php'],
    ];
    $cur_lang = ui_lang();
    // Păstrăm query string-ul curent (filtre etc.) când comutăm limba, doar
    // suprascriem/adăugăm parametrul lang.
    $lang_switch_qs = $_GET;
    ?>
<!DOCTYPE html>
<html lang="<?= e($cur_lang) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> — Front Door Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@700;900&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
<style>
/* ── RESET ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Jost',system-ui,sans-serif;font-weight:400;background-color:#000;background-image:radial-gradient(circle at 15% -10%,rgba(255,255,255,.09),transparent 45%),radial-gradient(circle at 100% 0%,rgba(255,255,255,.05),transparent 40%);background-attachment:fixed;color:#fff;font-size:15px;min-height:100vh;-webkit-font-smoothing:antialiased}
a{color:#fff;text-decoration:none}
button{font-family:inherit;cursor:pointer}
img{max-width:100%;display:block}

/* ── TOPBAR ── */
.topbar{background:rgba(10,10,10,.55);backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);border-bottom:1px solid rgba(255,255,255,.08);height:60px;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 28px;position:sticky;top:0;z-index:100;overflow:hidden}
.topbar-brand{font-family:'Nunito',sans-serif;font-weight:900;font-size:16px;letter-spacing:-.01em;color:#fff;display:flex;align-items:center;gap:10px;white-space:nowrap;flex-shrink:0}
.topbar-brand span{font-family:'Jost',sans-serif;font-weight:300;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.6)}
.topbar-user{display:flex;align-items:center;gap:14px;min-width:0}
.topbar-avatar{width:30px;height:30px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;font-family:'Nunito',sans-serif;font-size:12px;font-weight:900;color:#000;overflow:hidden;flex-shrink:0}
.topbar-avatar img{width:100%;height:100%;object-fit:cover}
.topbar-name{font-size:12px;font-weight:300;color:rgba(255,255,255,.65);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.topbar-logout{font-size:11px;font-weight:500;letter-spacing:.08em;color:rgba(255,255,255,.6);padding:6px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.03);transition:color .15s,border-color .15s,background .15s;white-space:nowrap;flex-shrink:0}
.topbar-logout:hover{color:#fff;border-color:rgba(255,255,255,.5);background:rgba(255,255,255,.08)}

/* ── TABS ── */
.tabs-bar{background:rgba(8,8,8,.5);backdrop-filter:blur(16px) saturate(150%);-webkit-backdrop-filter:blur(16px) saturate(150%);border-bottom:1px solid rgba(255,255,255,.08);padding:8px 24px;display:flex;gap:4px;overflow-x:auto;scrollbar-width:none;position:sticky;top:60px;z-index:99}
.tabs-bar::-webkit-scrollbar{display:none}
.tab-btn{padding:10px 16px;font-family:'Jost',sans-serif;font-size:13px;font-weight:400;letter-spacing:.04em;color:rgba(255,255,255,.6);border:none;border-radius:10px;background:transparent;cursor:pointer;transition:color .15s,background .15s;display:flex;align-items:center;gap:7px;white-space:nowrap;flex-shrink:0}
.tab-btn:hover{color:rgba(255,255,255,.85);background:rgba(255,255,255,.05)}
.tab-btn.active{color:#000;background:#fff;box-shadow:0 4px 14px rgba(255,255,255,.12)}
.tab-btn.disabled{opacity:.25;cursor:not-allowed;pointer-events:none}

/* ── CONTENT ── */
.content{padding:32px 28px;max-width:1200px;margin:0 auto}

/* ── FLASH ── */
.flash{padding:13px 18px;margin-bottom:24px;font-size:14px;font-weight:300;border:1px solid;border-radius:14px;backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
.flash-ok{border-color:rgba(255,255,255,.18);color:rgba(255,255,255,.75);background:rgba(255,255,255,.05)}
.flash-error{border-color:rgba(200,50,50,.35);color:rgba(255,150,150,.9);background:rgba(200,50,50,.08)}

/* ── PAGE HEAD ── */
.page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:14px}
.page-head h1{font-family:'Nunito',sans-serif;font-weight:900;font-size:22px;letter-spacing:-.01em}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;font-family:'Jost',sans-serif;font-size:12px;font-weight:500;letter-spacing:.06em;border:1px solid;border-radius:999px;cursor:pointer;transition:background .15s,color .15s,border-color .15s,transform .1s,box-shadow .15s;text-decoration:none;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-solid{background:#fff;color:#000;border-color:#fff;box-shadow:0 4px 16px rgba(255,255,255,.1)}.btn-solid:hover{opacity:.88;box-shadow:0 6px 20px rgba(255,255,255,.16)}
.btn-ghost{background:rgba(255,255,255,.04);backdrop-filter:blur(8px);color:rgba(255,255,255,.65);border-color:rgba(255,255,255,.18)}.btn-ghost:hover{color:#fff;border-color:rgba(255,255,255,.5);background:rgba(255,255,255,.09)}
.btn-danger{border-color:rgba(200,50,50,.4);color:rgba(255,120,120,.9);background:rgba(200,50,50,.05)}.btn-danger:hover{background:rgba(200,50,50,.14);border-color:rgba(200,50,50,.6)}
.btn-warn{border-color:rgba(220,120,0,.4);color:rgba(255,180,80,.9);background:rgba(220,120,0,.05)}.btn-warn:hover{background:rgba(220,120,0,.14)}
.btn-green{border-color:rgba(60,150,60,.4);color:rgba(120,200,120,.9);background:rgba(60,150,60,.05)}.btn-green:hover{background:rgba(60,150,60,.14)}
.btn-sm{padding:7px 14px;font-size:11px}
.btn-xs{padding:5px 11px;font-size:11px}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;border:1px solid rgba(255,255,255,.08);border-radius:16px;background:rgba(255,255,255,.025);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:12px 16px;font-size:10px;font-weight:500;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.5);border-bottom:1px solid rgba(255,255,255,.1);white-space:nowrap}
td{padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle;font-weight:300;font-size:14px}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(255,255,255,.03)}

/* ── BADGE ── */
.badge{display:inline-block;padding:3px 10px;font-size:10px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;border:1px solid currentColor;border-radius:999px}

/* ── FORM ── */
.form-section{background:rgba(255,255,255,.03);backdrop-filter:blur(16px) saturate(140%);-webkit-backdrop-filter:blur(16px) saturate(140%);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:24px;margin-bottom:18px;box-shadow:0 8px 30px rgba(0,0,0,.25)}
.section-label{font-family:'Jost',sans-serif;font-size:10px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:16px}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px}
@media(max-width:640px){.grid-2,.grid-3{grid-template-columns:1fr}}
.field{display:flex;flex-direction:column;gap:5px}
.field label{font-size:11px;font-weight:500;letter-spacing:.08em;color:rgba(255,255,255,.65)}
.field input,.field select,.field textarea{padding:10px 14px;font-size:14px;font-family:'Jost',sans-serif;font-weight:400;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.15);border-radius:10px;color:#fff;transition:border-color .15s,background .15s,box-shadow .15s}
.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:rgba(255,255,255,.55);background:rgba(255,255,255,.06);box-shadow:0 0 0 3px rgba(255,255,255,.08)}
.field textarea{resize:vertical;min-height:90px}
.field select option{background:#111}
.field-hint{font-size:11px;color:rgba(255,255,255,.45);font-weight:300}
.check-row{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:300;cursor:pointer}
.check-row input{width:15px;height:15px;accent-color:#fff;cursor:pointer}

/* ── FILTERS ── */
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:center}
.filters select{padding:8px 13px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.15);border-radius:999px;color:#fff;font-size:13px;font-family:'Jost',sans-serif;font-weight:300;transition:border-color .15s,background .15s}
.filters select:focus{outline:none;border-color:rgba(255,255,255,.55);background:rgba(255,255,255,.06)}
.filters select option{background:#111}

/* ── COVER ── */
.cover-thumb{width:44px;height:44px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,.1)}
.cover-empty{width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.04);border:1px dashed rgba(255,255,255,.14);display:flex;align-items:center;justify-content:center;font-size:9px;color:rgba(255,255,255,.3);letter-spacing:.08em}
.cover-preview{width:90px;height:90px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,.15);display:block;margin-bottom:8px}

/* ── EMPTY ── */
.empty{padding:56px;text-align:center;color:rgba(255,255,255,.35);border:1px dashed rgba(255,255,255,.14);border-radius:18px;background:rgba(255,255,255,.02);font-weight:300}
.empty a{color:rgba(255,255,255,.7);border-bottom:1px solid rgba(255,255,255,.2)}
/* butoanele .btn din interiorul .empty (ex. "Adaugă prima propunere") nu
   trebuie să moștenească stilul de link simplu de mai sus — altfel un
   .btn-solid (fundal alb) primește text alb de la ".empty a" și devine
   invizibil. Restaurăm explicit culorile + eliminăm sublinierea de link. */
.empty a.btn{border-bottom:none}
.empty a.btn-solid{color:#000}
.empty a.btn-ghost{color:rgba(255,255,255,.65)}

/* ── ACTIONS ROW ── */
.actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center}

/* ── TAGS ── */
.tags-wrap{display:flex;flex-wrap:wrap;gap:8px}
.tag-cb{display:none}
.tag-lbl{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;cursor:pointer;border:1px solid rgba(255,255,255,.14);border-radius:999px;background:rgba(255,255,255,.02);font-size:12px;font-weight:400;transition:border-color .15s,background .15s;user-select:none}
.tag-lbl:hover{border-color:rgba(255,255,255,.6)}
.tag-cb:checked + .tag-lbl{border-color:#fff;background:rgba(255,255,255,.09)}
.tag-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}

/* ── ERRORS ── */
.errors{background:rgba(200,50,50,.08);border:1px solid rgba(200,50,50,.28);border-radius:14px;padding:13px 18px;margin-bottom:18px;backdrop-filter:blur(10px)}
.errors li{font-size:13px;font-weight:300;color:rgba(255,150,150,.9);margin-left:16px;margin-top:3px}

/* ── MISC ── */
.access-denied{padding:48px 24px;text-align:center;color:rgba(255,255,255,.35);border:1px dashed rgba(200,50,50,.25);border-radius:18px;background:rgba(200,50,50,.03)}
.access-denied h2{font-size:18px;color:rgba(255,100,100,.7);margin-bottom:8px}

/* ── MOBILE ── */
@media(max-width:600px){
  .content{padding:20px 16px}
  .topbar{padding:0 14px;gap:8px}
  .topbar-brand{gap:6px;font-size:14px}
  .topbar-brand img{height:20px}
  .topbar-brand span{display:none}
  .topbar-user{gap:8px}
  .topbar-name{display:none}
  .topbar-logout{padding:5px 10px;font-size:10px}
  .tabs-bar{padding:6px 12px;top:60px}
  .tab-btn{padding:9px 13px;font-size:12px}
}
@media(max-width:380px){
  .topbar-brand{font-size:0}
  .topbar-brand img{height:22px}
}

/* ── FOCUS ── */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:2px solid #fff;outline-offset:2px}

/* ── LANG SWITCH ── */
.lang-switch{display:flex;gap:3px;background:rgba(255,255,255,.03);border-radius:999px;padding:2px}
.lang-switch a{font-size:11px;font-weight:700;letter-spacing:.04em;padding:5px 9px;color:rgba(255,255,255,.45);border-radius:999px;transition:color .15s,background .15s}
.lang-switch a:hover{color:rgba(255,255,255,.85)}
.lang-switch a.active{color:#000;background:#fff}
@media(max-width:600px){.lang-switch a{padding:5px 8px}}
</style>
</head>
<body>
<header class="topbar">
  <a class="topbar-brand" href="/admin/dashboard.php">
    <img src="/assets/logos/white_logo_square_transparent_background.png" alt="" style="height:24px;width:auto;flex-shrink:0">
    Front Door
    <span>Admin</span>
  </a>
  <div class="topbar-user">
    <div class="topbar-avatar">
      <?php if (!empty($user['avatar'])): ?>
        <img src="/<?= e(ltrim($user['avatar'],'/')) ?>" alt="">
      <?php else: ?>
        <?= e(mb_substr($user['name'] ?? 'U', 0, 1)) ?>
      <?php endif; ?>
    </div>
    <span class="topbar-name"><?= e($user['name'] ?? '') ?> · <?= e(position_label($user)) ?></span>
    <div class="lang-switch">
      <?php foreach (UI_LANGS as $lc => $lname):
        $qs = $lang_switch_qs; $qs['lang'] = $lc;
      ?>
        <a href="?<?= e(http_build_query($qs)) ?>" class="<?= $cur_lang === $lc ? 'active' : '' ?>" title="<?= e($lname) ?>"><?= strtoupper($lc) ?></a>
      <?php endforeach; ?>
    </div>
    <a class="topbar-logout" href="/admin/logout.php"><?= e(t('logout')) ?></a>
  </div>
</header>
<?php if ($active_tab !== 'dashboard'): ?>
<nav class="tabs-bar">
  <?php foreach ($tabs as $key => $tab):
    // Rolul decide UI-ul: un tab la care userul n-are 'view' nu doar se
    // dezactivează vizual, dispare complet din bară — nu trebuie să existe
    // niciun indiciu că secțiunea ar exista pentru cineva fără acces.
    if (!has_perm($user, $key, 'view')) continue;
    $isActive = $active_tab === $key;
  ?>
  <button
    class="tab-btn <?= $isActive ? 'active' : '' ?>"
    onclick="location.href='<?= $tab['url'] ?>'"
  ><?= $tab['label'] ?></button>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
    <?php
}

function layout_foot(): void {
    echo '</body></html>';
}
