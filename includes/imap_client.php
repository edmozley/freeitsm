<?php
/**
 * A small IMAP client in plain PHP - no `imap` extension (GH #257, #259, #50).
 *
 * PHP 8.4 removed ext/imap from PHP itself, and the C library under it (c-client,
 * last released 2007) is no longer packaged by Debian 13, which the Docker image
 * is built on. So Basic IMAP mailboxes could not read mail in the container, on
 * Debian 13, or on Windows PHP 8.4 without hunting down a DLL. This file speaks
 * the protocol itself, over a socket, the same way imapSmtpSend() has always
 * spoken SMTP. It needs only what every PHP build has: sockets, openssl,
 * base64/quoted-printable, and mbstring or iconv for character sets.
 *
 * It does ONLY what FreeITSM needs: sign in, list folders, open one, find unread
 * mail, read a message's structure and parts without marking it read, then mark
 * it read, move it or delete it. Developer guide: wiki "Basic-IMAP-Without-The-
 * Extension-Developer-Guide"; the protocol walk-through: "Basic-IMAP-Protocol-
 * Walkthrough".
 *
 * Three layers, top to bottom of this file:
 *   1. ImapClient       - the connection: TLS, commands, tags, literals, replies
 *   2. imapParse*()     - turning reply text into PHP values (lists, strings, NIL)
 *   3. imapBodyStructure / imapEnvelope - reading a message's BODYSTRUCTURE and
 *      ENVELOPE into plain arrays. The MAIL SERVER splits the message into parts;
 *      this file never parses raw MIME. That is the decision that keeps it small.
 */

require_once __DIR__ . '/ssl.php';
require_once __DIR__ . '/mail_ports.php';

/** A failure talking to an IMAP server. The message is safe to show an administrator. */
class ImapException extends Exception {}

/**
 * One connection to one IMAP server.
 *
 * Usage:
 *   $c = new ImapClient('mail.example.com', 993, 'ssl');
 *   $c->connect();
 *   $c->login($user, $pass);
 *   $c->select('INBOX');
 *   foreach ($c->uidSearch('UNSEEN') as $uid) { ... }
 *   $c->logout();
 */
class ImapClient
{
    /**
     * TRAP: the biggest single literal we will read into memory. A literal is the
     * server saying "the next N bytes are data" - a part of a message, a header.
     * Real mail tops out around 50 MB; the cap stops a broken or hostile server
     * sending {99999999999} and taking PHP's memory with it.
     */
    public const MAX_LITERAL = 128 * 1024 * 1024;

    /** @var resource|null */
    private $fp = null;
    private int $tagNo = 0;
    /** @var string[] upper-cased capability names, e.g. ['IMAP4REV1', 'MOVE', 'UIDPLUS'] */
    private array $caps = [];
    private ?string $selected = null;
    private int $exists = 0;

    /**
     * @param string $host  the server name, e.g. mail.example.com
     * @param int $port  993 for SSL, 143 for STARTTLS or none
     * @param string $encryption  'ssl' (TLS from the first byte), 'tls' (STARTTLS) or anything else for none
     * @param int $timeout  seconds to wait for the connection and for each read
     */
    public function __construct(
        private string $host,
        private int $port,
        private string $encryption = 'ssl',
        private int $timeout = 30
    ) {
        $this->encryption = strtolower(trim($encryption));
    }

    /** Closes the socket if it is still open. No LOGOUT - a destructor must never wait on the network. */
    public function __destruct()
    {
        if (is_resource($this->fp)) {
            @fclose($this->fp);
        }
    }

    // ------------------------------------------------------------------ connecting

    /**
     * Opens the socket, reads the server's greeting and, for 'tls', upgrades with STARTTLS.
     *
     * @throws ImapException when the server cannot be reached, TLS fails, or the greeting is not OK
     */
    public function connect(): void
    {
        if ($this->host === '') {
            throw new ImapException('No IMAP server configured for this mailbox.');
        }
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, imapStreamContext()
        );
        // Port 993 with STARTTLS, or 143 with SSL, cannot work - say which to change.
        $hint = mailPortMismatch('imap', $this->port, $this->encryption);
        if (!$fp) {
            $why = trim($errstr) !== '' ? $errstr : 'no response';
            if ($this->encryption === 'ssl' && stripos($why, 'certificate') !== false) {
                $why .= ' - the server\'s certificate could not be verified';
            }
            throw new ImapException('IMAP connection to ' . $this->host . ':' . $this->port . ' failed: ' . $why . ($hint ? ' ' . $hint : ''));
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;

        // The greeting is untagged: "* OK ...", "* PREAUTH ..." or "* BYE ...".
        try {
            $greeting = $this->readLogicalLine();
        } catch (ImapException $e) {
            throw new ImapException($this->host . ':' . $this->port . ' accepted the connection but never said hello (no IMAP greeting). '
                . ($hint ?? 'Check the port and the encryption setting - they usually go together: 993 with SSL, 143 with STARTTLS.'));
        }
        if (preg_match('/^\* BYE\b/i', $greeting)) {
            throw new ImapException('The IMAP server refused the connection: ' . trim(substr($greeting, 5)));
        }
        if (!preg_match('/^\* (OK|PREAUTH)\b/i', $greeting)) {
            throw new ImapException('That does not look like an IMAP server (it said "' . imapShorten($greeting) . '").');
        }
        $this->capturesCapabilities($greeting);

        if ($this->encryption === 'tls') {
            if (!$this->caps) {
                $this->capability();
            }
            if (!$this->hasCapability('STARTTLS')) {
                throw new ImapException('The IMAP server does not offer STARTTLS on port ' . $this->port . '. Choose SSL (usually port 993) instead.');
            }
            $this->command('STARTTLS');
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->fp, true, $crypto)) {
                throw new ImapException('IMAP STARTTLS negotiation failed - the server\'s certificate could not be verified, or it offers no TLS version this PHP accepts.');
            }
            // RFC 3501 6.2.1: capabilities learnt before TLS must be thrown away.
            $this->caps = [];
            $this->capability();
        }
    }

    /**
     * Signs in with a username and password (IMAP LOGIN).
     *
     * @param string $username  the mailbox login
     * @param string $password  the mailbox password
     * @throws ImapException naming the server's reason when it refuses
     */
    public function login(string $username, string $password): void
    {
        if (!$this->caps) {
            $this->capability();
        }
        // TRAP: a server that advertises LOGINDISABLED will not take a password on
        // this connection - typically because it is not encrypted. Say so, rather
        // than send the password anyway and report "authentication failed".
        if ($this->hasCapability('LOGINDISABLED')) {
            throw new ImapException('The IMAP server will not accept a password on an unencrypted connection. Set Encryption to SSL or STARTTLS.');
        }
        try {
            $this->command('LOGIN ' . imapString($username) . ' ' . imapString($password));
        } catch (ImapException $e) {
            throw new ImapException('IMAP sign-in failed: ' . $e->getMessage());
        }
        // The tagged OK often carries [CAPABILITY ...]; otherwise ask, because the
        // list can change after sign-in (MOVE and UIDPLUS commonly appear here).
        $this->caps = [];
        $this->capability();
    }

    /** Says goodbye politely and closes the socket. Errors are ignored - we are leaving anyway. */
    public function logout(): void
    {
        if (!is_resource($this->fp)) {
            return;
        }
        try {
            $this->command('LOGOUT');
        } catch (Throwable $e) {
            // The server may close before the tagged OK arrives; that is fine.
        }
        @fclose($this->fp);
        $this->fp = null;
    }

    // ------------------------------------------------------------------ capabilities

    /**
     * Asks the server what it supports and remembers the answer.
     *
     * @return string[] the capability names, upper-cased
     */
    public function capability(): array
    {
        $this->command('CAPABILITY');
        return $this->caps;
    }

    /**
     * True when the server advertised this capability.
     *
     * @param string $name  e.g. 'MOVE', 'UIDPLUS', 'STARTTLS'
     */
    public function hasCapability(string $name): bool
    {
        return in_array(strtoupper($name), $this->caps, true);
    }

    // ------------------------------------------------------------------ folders

    /**
     * Every folder on the server.
     *
     * @return array<int, array{name:string, delimiter:string, flags:string[]}> names decoded to UTF-8
     */
    public function listFolders(): array
    {
        $out = [];
        foreach ($this->command('LIST "" "*"') as $line) {
            if (!preg_match('/^\* LIST /i', $line)) {
                continue;
            }
            $v = imapParseValues(substr($line, 7));
            // (flags) delimiter name
            if (count($v) < 3) {
                continue;
            }
            $name = imapFolderDecode((string) $v[2]);
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name'      => $name,
                'delimiter' => is_string($v[1]) ? $v[1] : '',
                'flags'     => is_array($v[0]) ? array_map('strval', $v[0]) : [],
            ];
        }
        return $out;
    }

    /**
     * Opens a folder for reading and changing (IMAP SELECT).
     *
     * @param string $folder  the folder name in UTF-8, e.g. INBOX or INBOX.Support
     * @return int how many messages the folder holds
     * @throws ImapException when the folder does not exist or cannot be opened
     */
    public function select(string $folder): int
    {
        $this->exists = 0;
        foreach ($this->command('SELECT ' . imapString(imapFolderEncode($folder))) as $line) {
            if (preg_match('/^\* (\d+) EXISTS/i', $line, $m)) {
                $this->exists = (int) $m[1];
            }
        }
        $this->selected = $folder;
        return $this->exists;
    }

    /** The folder opened by select(), or null. */
    public function selectedFolder(): ?string
    {
        return $this->selected;
    }

    /** How many messages the selected folder held when it was opened. */
    public function messageCount(): int
    {
        return $this->exists;
    }

    // ------------------------------------------------------------------ messages

    /**
     * The UIDs of the messages matching a search, smallest (oldest) first.
     *
     * @param string $criteria  an IMAP search key, e.g. 'UNSEEN'. TRAP: this is sent
     *                          as it is - only ever pass a fixed key from code, never user input.
     * @return int[]
     */
    public function uidSearch(string $criteria): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH ' . $criteria) as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', rtrim($line), $m)) {
                foreach (preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) as $n) {
                    if (ctype_digit($n)) {
                        $uids[] = (int) $n;
                    }
                }
            }
        }
        $uids = array_values(array_unique($uids));
        sort($uids);
        return $uids;
    }

    /**
     * Fetches data items for one message by UID and returns them as name => value.
     *
     * Values are parsed: ENVELOPE and BODYSTRUCTURE come back as nested arrays,
     * a BODY[...] section as a string. Use the item names the server echoes,
     * e.g. 'ENVELOPE', 'BODYSTRUCTURE', 'BODY[1.2]', 'INTERNALDATE'.
     *
     * @param int $uid  the message's UID
     * @param string $items  what to fetch, e.g. '(ENVELOPE BODYSTRUCTURE)' or 'BODY.PEEK[2]'
     * @return array<string, mixed> empty when the message no longer exists
     */
    public function uidFetch(int $uid, string $items): array
    {
        $found = [];
        foreach ($this->command('UID FETCH ' . $uid . ' ' . $items) as $line) {
            if (!preg_match('/^\* \d+ FETCH /i', $line, $m)) {
                continue;
            }
            $values = imapParseValues(substr($line, strlen($m[0])));
            $list = $values[0] ?? null;
            if (!is_array($list)) {
                continue;
            }
            $attrs = [];
            for ($i = 0; $i + 1 < count($list); $i += 2) {
                $attrs[strtoupper((string) $list[$i])] = $list[$i + 1];
            }
            // TRAP: a server may send FETCH lines for OTHER messages at any time
            // (a flag changed elsewhere). Keep only the one whose UID we asked for.
            if (isset($attrs['UID']) && (int) $attrs['UID'] !== $uid) {
                continue;
            }
            $found = array_merge($found, $attrs);
        }
        return $found;
    }

    /**
     * Adds flags to a message, e.g. ['\Seen'] to mark it read.
     *
     * @param int $uid  the message's UID
     * @param string[] $flags  system flags such as '\Seen' or '\Deleted'
     */
    public function uidAddFlags(int $uid, array $flags): void
    {
        $this->command('UID STORE ' . $uid . ' +FLAGS.SILENT (' . implode(' ', $flags) . ')');
    }

    /**
     * Moves a message to another folder. Uses MOVE where the server has it, else
     * COPY, mark deleted and expunge - which is what MOVE does in one step.
     *
     * @param int $uid  the message's UID
     * @param string $folder  the destination folder in UTF-8
     */
    public function uidMove(int $uid, string $folder): void
    {
        $dest = imapString(imapFolderEncode($folder));
        if ($this->hasCapability('MOVE')) {
            $this->command('UID MOVE ' . $uid . ' ' . $dest);
            return;
        }
        $this->command('UID COPY ' . $uid . ' ' . $dest);
        $this->uidDelete($uid);
    }

    /**
     * Deletes a message: marks it \Deleted, then expunges.
     *
     * With UIDPLUS only that message is expunged. Without it a plain EXPUNGE also
     * removes anything else already marked \Deleted in the folder - which is what
     * the old extension-based code did every time with imap_expunge.
     *
     * @param int $uid  the message's UID
     */
    public function uidDelete(int $uid): void
    {
        $this->uidAddFlags($uid, ['\\Deleted']);
        $this->command($this->hasCapability('UIDPLUS') ? 'UID EXPUNGE ' . $uid : 'EXPUNGE');
    }

    // ------------------------------------------------------------------ the wire

    /**
     * Sends one command and reads up to its tagged reply.
     *
     * Returns the untagged lines ("* ...") that arrived on the way - a command's
     * results come back untagged, and the tagged line only says whether it worked.
     * A literal in a reply ({123} then 123 bytes) is kept inline in its line, so a
     * line can contain CRLFs; imapParseValues() understands the {n} marker.
     *
     * @param string $command  the command without its tag, e.g. 'SELECT "INBOX"'
     * @return string[] the untagged reply lines
     * @throws ImapException on NO or BAD (with the server's own words), or a dropped connection
     */
    public function command(string $command): array
    {
        if (!is_resource($this->fp)) {
            throw new ImapException('Not connected to the IMAP server.');
        }
        $tag = 'A' . str_pad((string) ++$this->tagNo, 4, '0', STR_PAD_LEFT);
        $this->sendCommand($tag, $command);

        $untagged = [];
        while (true) {
            $line = $this->readLogicalLine();
            if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) {
                $rest = substr($line, strlen($tag) + 1);
                $status = strtoupper(strtok($rest, ' '));
                $this->capturesCapabilities($rest);
                if ($status === 'OK') {
                    return $untagged;
                }
                $text = trim(substr($rest, strlen($status)));
                throw new ImapException($text !== '' ? imapShorten($text) : 'the server said ' . $status);
            }
            if (strncmp($line, '* ', 2) === 0) {
                $this->capturesCapabilities($line);
                $untagged[] = $line;
                continue;
            }
            // A "+ " continuation we did not ask for, or a stray line: ignore it.
        }
    }

    /**
     * Writes a command, splitting it at any {n} literal so the server can say
     * "+ go ahead" before the literal's bytes are sent (a synchronising literal).
     *
     * @param string $tag  the command's tag, e.g. A0003
     * @param string $command  the command text; literals appear as {n}\r\n followed by n bytes
     */
    private function sendCommand(string $tag, string $command): void
    {
        $data = $tag . ' ' . $command;
        $pos = 0;
        while (preg_match('/\{(\d+)\}\r\n/', $data, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $end = $m[0][1] + strlen($m[0][0]);
            $this->write(substr($data, $pos, $end - $pos));
            // Wait for "+ ..." before sending the literal; a tagged NO/BAD means refused.
            while (true) {
                $line = $this->readLogicalLine();
                if ($line !== '' && $line[0] === '+') {
                    break;
                }
                if (strncmp($line, $tag . ' ', strlen($tag) + 1) === 0) {
                    throw new ImapException(imapShorten(substr($line, strlen($tag) + 1)));
                }
            }
            $len = (int) $m[1][0];
            $this->write(substr($data, $end, $len));
            $pos = $end + $len;
        }
        $this->write(substr($data, $pos) . "\r\n");
    }

    /**
     * Writes bytes to the socket, all of them.
     *
     * @param string $bytes  what to send
     */
    private function write(string $bytes): void
    {
        $len = strlen($bytes);
        $sent = 0;
        while ($sent < $len) {
            $n = @fwrite($this->fp, substr($bytes, $sent));
            if ($n === false || $n === 0) {
                throw new ImapException('The connection to the IMAP server was lost while sending.');
            }
            $sent += $n;
        }
    }

    /**
     * Reads one LOGICAL line: a physical line, plus any literal it announces
     * ({n} at the end) and the rest of the line after the literal, and so on.
     */
    private function readLogicalLine(): string
    {
        $out = '';
        while (true) {
            $line = fgets($this->fp);
            if ($line === false) {
                $meta = stream_get_meta_data($this->fp);
                throw new ImapException(!empty($meta['timed_out'])
                    ? 'The IMAP server stopped responding (timed out after ' . $this->timeout . ' seconds).'
                    : 'The IMAP server closed the connection.' . ($out !== '' ? ' Last words: "' . imapShorten($out) . '"' : ''));
            }
            $out .= $line;
            if (!preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                return rtrim($out, "\r\n");
            }
            $len = (int) $m[1];
            if ($len > self::MAX_LITERAL) {
                throw new ImapException('The IMAP server sent a part of ' . $len . ' bytes, more than FreeITSM will read at once.');
            }
            $out .= $this->readExactly($len);
        }
    }

    /**
     * Reads exactly $len bytes - a literal's data, which may contain anything, CRLFs included.
     *
     * @param int $len  how many bytes
     */
    private function readExactly(int $len): string
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $chunk = fread($this->fp, min(65536, $len - strlen($buf)));
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->fp);
                if (!empty($meta['timed_out']) || feof($this->fp)) {
                    throw new ImapException('The connection to the IMAP server was lost part-way through a message.');
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    /**
     * Remembers capabilities when a line carries them - "* CAPABILITY ..." or a
     * "[CAPABILITY ...]" response code on a greeting or tagged OK.
     *
     * @param string $line  any reply line
     */
    private function capturesCapabilities(string $line): void
    {
        if (preg_match('/^\* CAPABILITY (.*)$/i', $line, $m) || preg_match('/\[CAPABILITY ([^\]]*)\]/i', $line, $m)) {
            $this->caps = array_values(array_filter(array_map('strtoupper', preg_split('/\s+/', trim($m[1])))));
        }
    }
}

// ====================================================================== helpers

/**
 * The TLS settings for an IMAP (or SMTP) socket - the app's one policy, the same
 * as every cURL call (includes/ssl.php): verify unless SSL_VERIFY_PEER is off,
 * and use the CA bundle PHP on Windows does not have of its own.
 *
 * @return resource a stream context
 */
function imapStreamContext()
{
    $verify = defined('SSL_VERIFY_PEER') ? (bool) SSL_VERIFY_PEER : true;
    $ssl = [
        'verify_peer'       => $verify,
        'verify_peer_name'  => $verify,
        'allow_self_signed' => !$verify,
    ];
    if ($verify) {
        $bundle = defined('SSL_CA_BUNDLE') ? (string) SSL_CA_BUNDLE : sslResolveCaBundle();
        if ($bundle !== '') {
            $ssl['cafile'] = $bundle;
        }
    }
    return stream_context_create(['ssl' => $ssl]);
}

/**
 * An IMAP string argument: "quoted" when safe, otherwise a literal {n}\r\n<bytes>.
 *
 * TRAP: this is the injection guard. A password containing a quote, a backslash
 * or a line break must never end the string early and start a new command.
 * Quoted strings escape " and \; anything with CR, LF, NUL or non-ASCII bytes is
 * sent as a literal, whose length is declared up front, so its content cannot be
 * read as a command whatever it holds.
 *
 * @param string $s  the value to send
 */
function imapString(string $s): string
{
    if (preg_match('/[\x00\r\n\x80-\xFF]/', $s)) {
        return '{' . strlen($s) . "}\r\n" . $s;
    }
    return '"' . addcslashes($s, '"\\') . '"';
}

/**
 * Encodes a UTF-8 folder name to IMAP's modified UTF-7 ("Übergabe" -> "&ANw-bergabe").
 *
 * @param string $name  the folder name in UTF-8
 */
function imapFolderEncode(string $name): string
{
    if (!preg_match('/[^\x20-\x7E]|&/', $name) || !function_exists('mb_convert_encoding')) {
        return $name;
    }
    $enc = @mb_convert_encoding($name, 'UTF7-IMAP', 'UTF-8');
    return is_string($enc) && $enc !== '' ? $enc : $name;
}

/**
 * Decodes a folder name from IMAP's modified UTF-7 to UTF-8.
 *
 * @param string $name  the name as the server sent it
 */
function imapFolderDecode(string $name): string
{
    if (strpos($name, '&') === false || !function_exists('mb_convert_encoding')) {
        return $name;
    }
    $dec = @mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
    return is_string($dec) && $dec !== '' ? $dec : $name;
}

/**
 * Shortens server text for an error message.
 *
 * @param string $s  what the server said
 */
function imapShorten(string $s): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    return mb_strlen($s) > 200 ? mb_substr($s, 0, 200) . '...' : $s;
}

/**
 * Parses IMAP reply data into PHP values.
 *
 *   (a b c)   -> array        "text"  -> string (unescaped)
 *   NIL       -> null         {3}\r\nabc -> string 'abc' (a literal)
 *   atom      -> string, e.g. UID, \Seen, BODY[1.2]
 *
 * @param string $s  the data, e.g. the part of a FETCH line after "* 12 FETCH "
 * @return array the top-level values in order
 */
function imapParseValues(string $s): array
{
    $pos = 0;
    $out = [];
    $len = strlen($s);
    while (true) {
        imapSkipSpaces($s, $pos);
        if ($pos >= $len) {
            break;
        }
        if ($s[$pos] === ')') { // unbalanced - stop rather than loop
            break;
        }
        $out[] = imapParseOne($s, $pos);
    }
    return $out;
}

/**
 * Parses one value at $pos and moves $pos past it.
 *
 * @param string $s  the data
 * @param int $pos  where to start; updated
 * @return mixed array, string or null
 */
function imapParseOne(string $s, int &$pos)
{
    $len = strlen($s);
    $c = $s[$pos];

    if ($c === '(') {
        $pos++;
        $list = [];
        while (true) {
            imapSkipSpaces($s, $pos);
            if ($pos >= $len) {
                return $list; // truncated - return what we have
            }
            if ($s[$pos] === ')') {
                $pos++;
                return $list;
            }
            $list[] = imapParseOne($s, $pos);
        }
    }

    if ($c === '"') {
        $pos++;
        $str = '';
        while ($pos < $len && $s[$pos] !== '"') {
            if ($s[$pos] === '\\' && $pos + 1 < $len) {
                $pos++;
            }
            $str .= $s[$pos++];
        }
        $pos++; // closing quote
        return $str;
    }

    if ($c === '{' && preg_match('/\G\{(\d+)\}\r\n/', $s, $m, 0, $pos)) {
        $n = (int) $m[1];
        $pos += strlen($m[0]);
        $str = (string) substr($s, $pos, $n);
        $pos += $n;
        return $str;
    }

    // An atom. Brackets are part of it, spaces inside them included:
    // BODY[1.2], BODY[HEADER.FIELDS (SUBJECT)], [UIDNEXT 5].
    $start = $pos;
    $depth = 0;
    while ($pos < $len) {
        $ch = $s[$pos];
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth = max(0, $depth - 1);
        } elseif ($depth === 0 && ($ch === ' ' || $ch === '(' || $ch === ')' || $ch === "\r" || $ch === "\n")) {
            break;
        }
        $pos++;
    }
    if ($pos === $start) { // a character no rule takes: step over it
        $pos++;
        return $c;
    }
    $atom = substr($s, $start, $pos - $start);
    return strtoupper($atom) === 'NIL' ? null : $atom;
}

/**
 * Moves $pos past spaces and line breaks.
 *
 * @param string $s  the data
 * @param int $pos  updated
 */
function imapSkipSpaces(string $s, int &$pos): void
{
    $len = strlen($s);
    while ($pos < $len && ($s[$pos] === ' ' || $s[$pos] === "\r" || $s[$pos] === "\n")) {
        $pos++;
    }
}

// ====================================================================== message structure

/**
 * Turns a parsed BODYSTRUCTURE into a tree of plain arrays - the shape the old
 * extension's imap_fetchstructure gave, as arrays instead of objects:
 *
 *   ['type' => 'text', 'subtype' => 'html', 'params' => ['charset' => 'utf-8'],
 *    'id' => '<logo@x>', 'encoding' => 'base64', 'disposition' => 'inline',
 *    'dparams' => ['filename' => 'logo.png'], 'parts' => [...]]
 *
 * Every key is lower-cased; params values are left as sent (RFC 2231 is decoded
 * by imapPartFilename()). A multipart node has type 'multipart' and its children
 * in 'parts'; the server numbers those 1, 2, 3 and 1.1, 1.2 inside them.
 *
 * @param mixed $bs  one BODYSTRUCTURE list from imapParseValues()
 */
function imapBodyStructure($bs): array
{
    if (!is_array($bs) || !$bs) {
        return ['type' => 'text', 'subtype' => 'plain', 'params' => [], 'id' => null, 'encoding' => '7bit', 'disposition' => null, 'dparams' => [], 'parts' => []];
    }

    // Multipart: one or more child lists first, then the subtype.
    if (is_array($bs[0])) {
        $parts = [];
        $i = 0;
        while ($i < count($bs) && is_array($bs[$i])) {
            $parts[] = imapBodyStructure($bs[$i]);
            $i++;
        }
        $subtype = strtolower((string) ($bs[$i] ?? 'mixed'));
        [$disposition, $dparams] = imapDisposition($bs[$i + 2] ?? null);
        return [
            'type' => 'multipart', 'subtype' => $subtype,
            'params' => imapParamList($bs[$i + 1] ?? null),
            'id' => null, 'encoding' => '7bit',
            'disposition' => $disposition, 'dparams' => $dparams,
            'parts' => $parts,
        ];
    }

    $type = strtolower((string) ($bs[0] ?? 'text'));
    $subtype = strtolower((string) ($bs[1] ?? 'plain'));
    // The extension fields start after the type-specific ones:
    //   text:            7 = lines            -> md5 at 8, disposition at 9
    //   message/rfc822:  7 envelope, 8 body, 9 lines -> md5 at 10, disposition at 11
    //   anything else:   md5 at 7, disposition at 8
    if ($type === 'text') {
        $dispAt = 9;
    } elseif ($type === 'message' && $subtype === 'rfc822') {
        $dispAt = 11;
    } else {
        $dispAt = 8;
    }
    [$disposition, $dparams] = imapDisposition($bs[$dispAt] ?? null);

    return [
        'type' => $type,
        'subtype' => $subtype,
        'params' => imapParamList($bs[2] ?? null),
        'id' => isset($bs[3]) ? (string) $bs[3] : null,
        'encoding' => strtolower((string) ($bs[5] ?? '7bit')),
        'disposition' => $disposition,
        'dparams' => $dparams,
        'parts' => [],
    ];
}

/**
 * A ("name" "value" "name" "value") list as ['name' => 'value'], names lower-cased.
 *
 * @param mixed $list  the parsed list, or null (NIL)
 */
function imapParamList($list): array
{
    $out = [];
    if (!is_array($list)) {
        return $out;
    }
    for ($i = 0; $i + 1 < count($list); $i += 2) {
        if (is_string($list[$i])) {
            $out[strtolower($list[$i])] = (string) $list[$i + 1];
        }
    }
    return $out;
}

/**
 * A disposition ("attachment" ("filename" "a.pdf")) as [disposition, params].
 *
 * @param mixed $d  the parsed disposition, or null
 * @return array{0:?string, 1:array}
 */
function imapDisposition($d): array
{
    if (!is_array($d) || !isset($d[0]) || !is_string($d[0])) {
        return [null, []];
    }
    return [strtolower($d[0]), imapParamList($d[1] ?? null)];
}

/**
 * A part's filename, from the disposition's filename or the type's name,
 * decoded from both encodings mail programs use:
 *   RFC 2231 - filename*=utf-8''%C3%9Cbersicht.pdf, possibly split as filename*0*, filename*1*
 *   RFC 2047 - filename="=?UTF-8?B?w5xiZXJzaWNodC5wZGY=?="
 *
 * @param array $part  one node from imapBodyStructure()
 */
function imapPartFilename(array $part): string
{
    foreach ([[$part['dparams'] ?? [], 'filename'], [$part['params'] ?? [], 'name']] as [$params, $key]) {
        $name = imapRfc2231Param($params, $key);
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

/**
 * One parameter, joining RFC 2231 continuations and decoding %XX with its charset.
 *
 * @param array $params  the part's parameters, keys lower-cased
 * @param string $key  e.g. 'filename'
 */
function imapRfc2231Param(array $params, string $key): string
{
    // TRAP: the RFC 2231 form WINS when both are there. Mail programs (FreeITSM's
    // own sender included) send filename="f__r.pdf" as an ASCII fallback AND
    // filename*=UTF-8''f%C3%BCr.pdf as the real name. Reading the plain one first
    // turned "Bericht für Sie.pdf" into "Bericht f__r Sie.pdf" - found by the
    // send-then-read round trip, not by any single-sided test.
    // key* (one encoded value) or key*0, key*1* ... (continuations, some encoded)
    $pieces = [];
    $encoded = [];
    foreach ($params as $k => $v) {
        if ($k === $key . '*') {
            $pieces[0] = $v;
            $encoded[0] = true;
        } elseif (preg_match('/^' . preg_quote($key, '/') . '\*(\d+)(\*?)$/', $k, $m)) {
            $pieces[(int) $m[1]] = $v;
            $encoded[(int) $m[1]] = $m[2] === '*';
        }
    }
    if (!$pieces) {
        return isset($params[$key]) ? imapDecodeMimeHeader($params[$key]) : '';
    }
    ksort($pieces);
    $charset = 'UTF-8';
    $out = '';
    foreach ($pieces as $i => $v) {
        if (!empty($encoded[$i])) {
            if ($i === array_key_first($pieces) && preg_match("/^([^']*)'[^']*'(.*)$/s", $v, $m)) {
                $charset = $m[1] !== '' ? $m[1] : $charset;
                $v = $m[2];
            }
            $v = rawurldecode($v);
        }
        $out .= $v;
    }
    return imapCharsetToUtf8($out, $charset);
}

/**
 * Turns a parsed ENVELOPE into the fields the importer reads.
 *
 * ENVELOPE is (date subject from sender reply-to to cc bcc in-reply-to message-id);
 * each address list is ((name route mailbox host) ...). The server has already
 * split the addresses, so no address parser is needed here.
 *
 * @param mixed $env  the parsed ENVELOPE list
 * @return array{date:string, subject:string, from:array, to:array, cc:array, message_id:string}
 */
function imapEnvelope($env): array
{
    $env = is_array($env) ? $env : [];
    return [
        'date'       => (string) ($env[0] ?? ''),
        'subject'    => imapDecodeMimeHeader((string) ($env[1] ?? '')),
        'from'       => imapEnvelopeAddresses($env[2] ?? null),
        'to'         => imapEnvelopeAddresses($env[5] ?? null),
        'cc'         => imapEnvelopeAddresses($env[6] ?? null),
        'message_id' => trim((string) ($env[9] ?? '')),
    ];
}

/**
 * An ENVELOPE address list in the Graph-like recipient shape FreeITSM uses:
 * [['emailAddress' => ['name' => 'Jo Bloggs', 'address' => 'jo@example.com']], ...]
 *
 * Group markers ((NIL NIL "group" NIL) ... (NIL NIL NIL NIL)) carry no address and are skipped.
 *
 * @param mixed $list  the parsed address list, or null
 */
function imapEnvelopeAddresses($list): array
{
    $out = [];
    if (!is_array($list)) {
        return $out;
    }
    foreach ($list as $a) {
        if (!is_array($a) || empty($a[2]) || empty($a[3])) {
            continue; // a group start/end, or a malformed entry
        }
        $host = (string) $a[3];
        if ($host === '.SYNTAX-ERROR.' || str_starts_with($host, '.MISSING')) {
            continue;
        }
        $out[] = ['emailAddress' => [
            'name'    => imapDecodeMimeHeader((string) ($a[0] ?? '')),
            'address' => $a[2] . '@' . $host,
        ]];
    }
    return $out;
}

/**
 * Decodes RFC 2047 encoded words in a header to UTF-8:
 *   =?UTF-8?B?w5xiZXJzaWNodA==?=  and  =?ISO-8859-1?Q?caf=E9?=
 * Whitespace BETWEEN two encoded words is dropped (RFC 2047 section 6.2); text
 * that is not encoded is kept as it is.
 *
 * @param string $value  the raw header text
 */
function imapDecodeMimeHeader(string $value): string
{
    if ($value === '' || strpos($value, '=?') === false) {
        return trim($value);
    }
    $value = preg_replace('/(=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=)\s+(?==\?[^?\s]+\?[BbQq]\?)/', '$1', $value);
    $out = preg_replace_callback('/=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/', function ($m) {
        $charset = preg_replace('/\*.*$/', '', $m[1]); // drop an RFC 2231 language, utf-8*en
        if (strtoupper($m[2]) === 'B') {
            $text = base64_decode($m[3], false);
            $text = $text === false ? '' : $text;
        } else {
            $text = quoted_printable_decode(str_replace('_', ' ', $m[3]));
        }
        return imapCharsetToUtf8($text, $charset);
    }, $value);
    return trim((string) $out);
}

/**
 * Converts text to UTF-8 from a declared charset, best effort.
 *
 * @param string $data  the text
 * @param string $charset  as declared; blank means UTF-8
 */
function imapCharsetToUtf8(string $data, string $charset): string
{
    $charset = trim($charset) ?: 'UTF-8';
    if (strcasecmp($charset, 'UTF-8') === 0 || strcasecmp($charset, 'US-ASCII') === 0 || strcasecmp($charset, 'UTF8') === 0) {
        return $data;
    }
    // Mail calls it Latin-1 and means Windows-1252 more often than not (curly quotes, the euro).
    if (strcasecmp($charset, 'ISO-8859-1') === 0 || strcasecmp($charset, 'LATIN1') === 0) {
        $charset = 'Windows-1252';
    }
    if (function_exists('mb_convert_encoding')) {
        try {
            $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        } catch (ValueError $e) {
            // mbstring does not know this charset - try iconv
        }
    }
    if (function_exists('iconv')) {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
        if ($converted !== false) {
            return $converted;
        }
    }
    return $data;
}

/**
 * Undoes a part's transfer encoding.
 *
 * @param string $data  the part as fetched
 * @param string $encoding  'base64', 'quoted-printable', or anything else (7bit, 8bit, binary) returned as is
 */
function imapDecodeTransfer(string $data, string $encoding): string
{
    switch (strtolower($encoding)) {
        case 'base64':
            $out = base64_decode($data, false);
            return $out === false ? '' : $out;
        case 'quoted-printable':
            return quoted_printable_decode($data);
        default:
            return $data;
    }
}
