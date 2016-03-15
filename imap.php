<?php
/**
 * oHuangKeo @ 2016-03-15
 */
# 1. 获取所有发件UID
# 2. 读取本地历史，去除已处理UID
# 3. 读取未处理UID信息，转发并更新本地历史

define('BACKUP', 'ohko@qq.com'); // 备份邮箱
define('ACCOUNT', __DIR__ . '/account.txt'); // 格式：{imap.mxhichina.com:143}Sent,xx@xx.com,******
define('HISTORY', __DIR__ . '/history/');
define('SLEEP_TIME', 15 * 60); // 每15分钟备份一次

$imap = new IMAP_BACKUP();
while (1) {
    $accounts = $imap->getAllAccount();

    echo date('Y-m-d H:i:s'), "\n";
    foreach ($accounts as $account) {
        $uids = $imap->getAllUID($account[0], $account[1], $account[2]);
        $history = $imap->getHistory($account[0], $account[1]);
        $diff = array_diff($uids, $history);

        echo str_repeat('=', 50), "\n";
        echo 'Account: ', $account[0], ' ', $account[1], "\n";
        echo 'Server  UIDs:', count($uids), "\n";
        echo 'History UIDs:', count($history), "\n";

        foreach ($diff as $uid) {
            echo str_repeat('-', 50), "\n";
            $imap->backupUIDs($uid);
            break;
        }
    }

    sleep(SLEEP_TIME);
}

class IMAP_BACKUP
{
    private $imapConn;
    private $server;
    private $user;
    private $pass;
    private $box;

    // 读取帐号配置
    public function getAllAccount()
    {
        file_exists(ACCOUNT) || die(ACCOUNT . ' Not found!');
        $file = file_get_contents(ACCOUNT);
        $array = explode("\n", $file);
        $account = [];
        foreach ($array as $one) {
            if (!trim($one)) continue;
            $account[] = explode(',', $one);
        }
        return $account;
    }

    // 获取所有邮件UID
    public function getAllUID($server, $user, $pass)
    {
        $this->server = $server;
        $this->user = $user;
        $this->pass = $pass;
        $this->box = explode('}', $server)[1];
        $this->imapConn = @imap_open($server, $user, $pass, OP_READONLY, 1);
        if (!$this->imapConn) return false;
        $r = imap_search($this->imapConn, 'ALL', SE_UID);
        if ($r) return $r;
        return [];
    }

    // 读取本地历史
    public function getHistory($server, $user)
    {
        file_exists(HISTORY) || mkdir(HISTORY);
        $historyFile = HISTORY . $user . $server;
        file_exists(HISTORY) || die(HISTORY . ' Not found!');
        if (file_exists($historyFile)) {
            return include($historyFile);
        }
        return [];
    }

    // 读取未处理UID信息
    public function backupUIDs($uid)
    {
        // 读取邮件
        $ov = imap_fetch_overview($this->imapConn, $uid, FT_UID);
        $from = @iconv_mime_decode($ov[0]->from);
        if (isset($ov[0]->to)) $to = iconv_mime_decode($ov[0]->to);
        else $to = '';
        $subject = @iconv_mime_decode($ov[0]->subject);
        $header = imap_fetchheader($this->imapConn, $uid, FT_UID);
        $body = imap_body($this->imapConn, $uid, FT_UID);

        echo 'from:   ', $from . '_' . $ov[0]->from, "\n";
        echo 'to:     ', $to, "\n";
        echo 'subject:', $subject, "\n";
        echo 'time:   ', date('Y-m-d H:i:s', $ov[0]->udate), "\n";

        // 去掉body多余的头
        $header = substr($header, stripos($header, 'Content-Type:'));

        // 发送邮件
//        if (1) {
        if (imap_mail(BACKUP, "[BACKUP_{$this->box}_{$from}_{$to}]" . $subject, $body, $header)) {
            // 更新history
            echo "backup: SUCCESS!\n";
            $this->updateHistory($uid);
        } else {
            echo "backup: FAILED!\n";
        }


    }

    private function updateHistory($uid)
    {
        $historyFile = HISTORY . $this->user . $this->server;
        $uids = [$uid];
        if (file_exists($historyFile)) {
            $uids = array_merge(include($historyFile), $uids);
        }
        file_put_contents($historyFile, "<?php\nreturn [" . implode(',', $uids) . '];');
    }
}