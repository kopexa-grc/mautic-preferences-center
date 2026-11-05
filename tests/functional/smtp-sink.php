<?php
// Minimal SMTP server for the functional test: accepts every message and
// writes it to <dir>/<n>.eml. No TLS, no auth.
//   php smtp-sink.php <port> <dir>
declare(strict_types=1);

[$port, $dir] = [(int) ($argv[1] ?? 2525), $argv[2] ?? sys_get_temp_dir()];
$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $error) ?: exit("smtp-sink: $error\n");
$n      = 0;
while ($client = stream_socket_accept($server, -1)) {
    $say = static fn (string $line) => fwrite($client, $line."\r\n");
    $say('220 smtp-sink ready');
    $data = null;
    while (false !== ($line = fgets($client))) {
        if (null !== $data) {
            if (".\r\n" === $line || ".\n" === $line) {
                file_put_contents(sprintf('%s/%03d.eml', $dir, ++$n), $data);
                $data = null;
                $say('250 queued');
            } else {
                $data .= str_starts_with($line, '..') ? substr($line, 1) : $line;
            }
            continue;
        }
        $verb = strtoupper(substr(trim($line), 0, 4));
        match ($verb) {
            'EHLO'  => $say("250-smtp-sink\r\n250 8BITMIME"),
            'HELO'  => $say('250 smtp-sink'),
            'DATA'  => [$say('354 end with .'), $data = ''],
            'QUIT'  => $say('221 bye'),
            default => $say('250 ok'),
        };
        if ('QUIT' === $verb) {
            break;
        }
    }
    fclose($client);
}
