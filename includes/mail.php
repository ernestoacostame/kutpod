<?php
// includes/mail.php - Cliente SMTP por sockets nativos de PHP (0 dependencias)

function kp_send_mail(string $to, string $subject, string $body): bool {
  $host = kp_setting('smtp_host', '');
  $port = (int)kp_setting('smtp_port', 465);
  $user = kp_setting('smtp_user', '');
  $pass = kp_setting('smtp_pass', '');
  $crypto = kp_setting('smtp_crypto', 'ssl');
  
  $inst = kp_instance();
  $domain = $inst['domain'] ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
  $from = kp_setting('from_email', 'noreply@' . $domain);
  
  $headers = "From: KutPod <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n";
  
  // Fallback a mail() de PHP si no hay SMTP configurado
  if (!$host) {
    return @mail($to, $subject, $body, $headers);
  }
  
  $host_str = $crypto === 'ssl' ? 'ssl://' . $host : $host;
  
  $socket = @fsockopen($host_str, $port, $errno, $errstr, 15);
  if (!$socket) {
      error_log("SMTP Error: No se pudo conectar a $host_str:$port - $errstr");
      try { kp_alert('system', 'Error de conexión SMTP', "No se pudo conectar a $host_str:$port ($errstr)", '/admin/preferences', []); } catch (Throwable $e) {}
      return false;
  }
  
  $read_res = function(array $expected = []) use ($socket, $host_str, $to) {
    $data = "";
    while ($str = fgets($socket, 515)) {
      $data .= $str;
      if (substr($str, 3, 1) === ' ') break;
    }
    if (!empty($expected)) {
        $code = substr($data, 0, 3);
        if (!in_array($code, $expected)) {
            $err = "SMTP Error ($host_str): Expected " . implode(',', $expected) . " but got: " . trim($data);
            error_log($err);
            try { kp_alert('system', 'Error de envío (SMTP)', "Fallo enviando correo a $to. $err", '/admin/preferences', []); } catch (Throwable $e) {}
            return false;
        }
    }
    return $data;
  };
  
  if ($read_res(['220']) === false) return false;
  
  fwrite($socket, "EHLO $domain\r\n");
  if ($read_res(['250']) === false) return false;
  
  if ($crypto === 'tls') {
    fwrite($socket, "STARTTLS\r\n");
    if ($read_res(['220']) === false) return false;
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    fwrite($socket, "EHLO $domain\r\n");
    if ($read_res(['250']) === false) return false;
  }
  
  if ($user && $pass) {
    fwrite($socket, "AUTH LOGIN\r\n");
    if ($read_res(['334']) === false) return false;
    fwrite($socket, base64_encode($user) . "\r\n");
    if ($read_res(['334']) === false) return false;
    fwrite($socket, base64_encode($pass) . "\r\n");
    if ($read_res(['235']) === false) return false;
  }
  
  fwrite($socket, "MAIL FROM: <$from>\r\n");
  if ($read_res(['250']) === false) return false;
  
  fwrite($socket, "RCPT TO: <$to>\r\n");
  if ($read_res(['250', '251']) === false) return false;
  
  fwrite($socket, "DATA\r\n");
  if ($read_res(['354']) === false) return false;
  
  // Construir el mensaje completo
  $msg = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
  $msg .= "To: $to\r\n";
  $msg .= $headers . "\r\n";
  $msg .= $body . "\r\n.\r\n";
  
  fwrite($socket, $msg);
  if ($read_res(['250']) === false) return false;
  
  fwrite($socket, "QUIT\r\n");
  $read_res();
  
  fclose($socket);
  return true;
}
