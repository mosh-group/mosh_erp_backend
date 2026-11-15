<?php
namespace App\Services\Social;
/** Provider boundary for future platforms. Storage, authorisation and outbox remain in MOSH. */
interface Publisher
{
    public function publish(array $account,array $post,?string $mediaUrl,array $config): array;
    public function inspect(array $account,array $post,array $config): array;
    public function insights(array $account,int $days,array $config): array;
}
