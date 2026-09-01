<?php
/* =============================================================
   Foreningen Front Door — admin/mailer.php
   Trimite email prin SMTP SSL folosind socket nativ PHP.
   Nu necesită PHPMailer sau alte librării externe.
   ============================================================= */

require_once __DIR__ . '/smtp-config.php';

/**
 * Trimite un email prin SMTP SSL.
 *
 * @param string $to      Adresa destinatarului
 * @param string $to_name Numele destinatarului
 * @param string $subject Subiectul emailului
 * @param string $body    Corpul emailului (HTML sau text)
 * @param bool   $is_html True dacă body e HTML
 * @return bool|string    True la succes, mesaj de eroare la eșec
 */
function send_smtp_mail(
    string $to,
    string $to_name,
    string $subject,
    string $body,
    bool $is_html = false
): bool|string {

    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $user    = SMTP_USER;
    $pass    = SMTP_PASS;
    $from    = SMTP_FROM;
    $fname   = SMTP_FROM_NAME;

    // Conectare SSL
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
        ]
    ]);

    $socket = @stream_socket_client(
        "ssl://{$host}:{$port}",
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        return "Conexiune SMTP eșuată: {$errstr} ({$errno})";
    }

    // Helper read/write
    $read = function() use ($socket): string {
        $resp = '';
        while ($line = fgets($socket, 512)) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $resp;
    };

    $send = function(string $cmd) use ($socket): void {
        fwrite($socket, $cmd . "\r\n");
    };

    // SMTP handshake
    $read(); // 220 greeting
    $send("EHLO " . gethostname());
    $read();

    // AUTH LOGIN
    $send("AUTH LOGIN");
    $read();
    $send(base64_encode($user));
    $read();
    $send(base64_encode($pass));
    $r = $read();
    if (strpos($r, '235') === false) {
        fclose($socket);
        return "Autentificare SMTP eșuată: {$r}";
    }

    // MAIL FROM
    $send("MAIL FROM:<{$from}>");
    $read();

    // RCPT TO
    $send("RCPT TO:<{$to}>");
    $r = $read();
    if (strpos($r, '250') === false) {
        fclose($socket);
        return "RCPT TO eșuat: {$r}";
    }

    // DATA
    $send("DATA");
    $read();

    // Build message
    $boundary = md5(uniqid());
    $date     = date('r');
    $subj_enc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $to_enc   = $to_name ? "=?UTF-8?B?" . base64_encode($to_name) . "?= <{$to}>" : $to;
    $from_enc = "=?UTF-8?B?" . base64_encode($fname) . "?= <{$from}>";

    if ($is_html) {
        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $body));
        $msg  = "Date: {$date}\r\n";
        $msg .= "From: {$from_enc}\r\n";
        $msg .= "To: {$to_enc}\r\n";
        $msg .= "Subject: {$subj_enc}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $msg .= "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($plain)) . "\r\n";
        $msg .= "--{$boundary}\r\n";
        $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n";
        $msg .= "--{$boundary}--\r\n";
    } else {
        $msg  = "Date: {$date}\r\n";
        $msg .= "From: {$from_enc}\r\n";
        $msg .= "To: {$to_enc}\r\n";
        $msg .= "Subject: {$subj_enc}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $msg .= chunk_split(base64_encode($body)) . "\r\n";
    }

    $msg .= "\r\n.\r\n";
    fwrite($socket, $msg);
    $r = $read();
    if (strpos($r, '250') === false) {
        fclose($socket);
        return "Trimitere eșuată: {$r}";
    }

    $send("QUIT");
    fclose($socket);
    return true;
}
