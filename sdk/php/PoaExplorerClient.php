<?php
declare(strict_types=1);

/**
 * Read-only client for the PoA explorer JSON API.
 * AGPLv3. No keys. Never talks to /api/hosted.
 */
final class PoaExplorerClient
{
    private string $base;
    private int $timeout;

    public function __construct(string $baseUrl = 'https://poa.explorer.zero1.network', int $timeout = 20)
    {
        $this->base = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    public function status(): array
    {
        return $this->get('/api/status.php');
    }

    public function recentTxs(): array
    {
        return $this->get('/api/txs.php');
    }

    public function tx(string $id): array
    {
        return $this->get('/api/tx.php', ['id' => $id]);
    }

    public function address(string $addr): array
    {
        return $this->get('/api/address.php', ['a' => $addr]);
    }

    public function anchors(): array
    {
        return $this->get('/api/anchors.php');
    }

    public function epochs(): array
    {
        return $this->get('/api/epochs.php');
    }

    public function challenges(): array
    {
        return $this->get('/api/challenges.php');
    }

    public function changelog(): array
    {
        return $this->get('/api/changelog.php');
    }

    public function close(string $epoch): array
    {
        return $this->get('/api/close.php', ['epoch' => $epoch]);
    }

    public function pending(array $query = []): array
    {
        return $this->get('/api/pending.php', $query);
    }

    public function reports(): array
    {
        return $this->get('/api/reports.php');
    }

    public function health(): array
    {
        return $this->get('/api/health.php');
    }

    public function market(): array
    {
        return $this->get('/api/market.php');
    }

    /**
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->base . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => "Accept: application/json\r\nUser-Agent: PoaExplorerClient/0.3 PHP\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('explorer unreachable: ' . $url);
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('explorer returned non-JSON');
        }
        return $decoded;
    }
}
