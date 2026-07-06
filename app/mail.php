<?php
/**
 * Envoi d'emails sans dépendance externe.
 *
 * Trois transports (constante MAIL_DRIVER) :
 *   'mail' — mail() de PHP, disponible sur la plupart des hébergements mutualisés
 *   'smtp' — client SMTP minimal intégré (STARTTLS / SSL / AUTH LOGIN)
 *   'log'  — écrit l'email dans storage/mail.log (développement)
 */

/**
 * Envoie un email HTML (avec alternative texte). Retourne true si le
 * transport a accepté le message.
 */
function send_mail(string $to, string $subject, string $html, string $text): bool
{
    $driver = defined('MAIL_DRIVER') ? MAIL_DRIVER : 'mail';

    if ($driver === 'log') {
        $line = sprintf(
            "===== %s =====\nTo: %s\nSubject: %s\n\n%s\n\n",
            now(),
            $to,
            $subject,
            $text
        );
        $dir = dirname(__DIR__) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Le driver 'log' est réservé au développement ; il consigne codes et
        // liens magiques en clair. Nom de fichier non devinable (dérivé
        // d'APP_KEY) en défense en profondeur si .htaccess n'est pas honoré.
        return (bool) file_put_contents($dir . '/' . mail_log_name(), $line, FILE_APPEND | LOCK_EX);
    }

    $boundary = 'b' . random_hex(16);
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
        : $subject;
    $fromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader(MAIL_FROM_NAME, 'UTF-8', 'B', "\r\n")
        : MAIL_FROM_NAME;

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $text . "\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $html . "\r\n\r\n"
        . "--$boundary--\r\n";

    $headers = [
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        'From: ' . $fromName . ' <' . MAIL_FROM . '>',
        'Date: ' . gmdate('r'),
    ];

    if ($driver === 'smtp') {
        return smtp_send($to, $encodedSubject, $headers, $body);
    }

    return mail($to, $encodedSubject, $body, implode("\r\n", $headers));
}

/** Client SMTP minimal : EHLO, STARTTLS/SSL, AUTH LOGIN, envoi. */
function smtp_send(string $to, string $subject, array $headers, string $body): bool
{
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    $host   = ($secure === 'ssl' ? 'ssl://' : '') . SMTP_HOST;

    $fp = @stream_socket_client($host . ':' . SMTP_PORT, $errno, $errstr, 15);
    if (!$fp) {
        error_log("SMTP: connexion impossible à $host: $errstr");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') { // fin de réponse multi-lignes
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $command, array $expect) use ($fp, $read): string {
        fwrite($fp, $command . "\r\n");
        $resp = $read();
        if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
            throw new RuntimeException("SMTP: réponse inattendue à « $command » : " . trim($resp));
        }
        return $resp;
    };

    try {
        if ((int) substr($read(), 0, 3) !== 220) {
            throw new RuntimeException('SMTP: pas de bannière 220.');
        }
        $cmd('EHLO ' . parse_url(APP_URL, PHP_URL_HOST), [250]);

        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP: échec STARTTLS.');
            }
            $cmd('EHLO ' . parse_url(APP_URL, PHP_URL_HOST), [250]);
        }

        if (SMTP_USER !== '') {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode(SMTP_USER), [334]);
            $cmd(base64_encode(SMTP_PASS), [235]);
        }

        $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);

        $data = 'To: <' . $to . ">\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . implode("\r\n", $headers) . "\r\n\r\n"
            . preg_replace('/^\./m', '..', $body)
            . "\r\n.";
        $cmd($data, [250]);
        $cmd('QUIT', [221]);
        fclose($fp);
        return true;
    } catch (RuntimeException $e) {
        error_log($e->getMessage());
        @fclose($fp);
        return false;
    }
}

/** Nom du fichier de journal du driver 'log', dérivé d'APP_KEY (dev uniquement). */
function mail_log_name(): string
{
    return 'mail-' . substr(hash('sha256', 'maillog|' . APP_KEY), 0, 16) . '.log';
}

/** Gabarit HTML minimaliste commun à tous les emails de l'application. */
function mail_template(string $title, string $bodyHtml): string
{
    $app = e(APP_NAME);
    return <<<HTML
<!doctype html>
<html lang="fr">
<body style="margin:0;padding:0;background:#f5f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:480px;margin:0 auto;padding:40px 20px;">
    <p style="font-size:15px;font-weight:600;color:#1d1d1f;margin:0 0 24px;">$app</p>
    <div style="background:#ffffff;border-radius:18px;padding:32px;box-shadow:0 1px 3px rgba(0,0,0,.08);">
      <h1 style="font-size:21px;color:#1d1d1f;margin:0 0 16px;">$title</h1>
      $bodyHtml
    </div>
    <p style="font-size:12px;color:#86868b;margin:24px 0 0;text-align:center;">
      Vous recevez cet email car votre adresse a été utilisée sur $app.<br>
      Si vous n'êtes pas à l'origine de cette action, ignorez simplement ce message.
    </p>
  </div>
</body>
</html>
HTML;
}
