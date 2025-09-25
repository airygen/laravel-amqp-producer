<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Airygen\RabbitMQ\Factories\ConnectionFactory;
use Airygen\RabbitMQ\Factories\MessageFactory;
use Airygen\RabbitMQ\Publisher;
use Airygen\RabbitMQ\ProducerPayload;
use Airygen\RabbitMQ\Support\ConnectionManager;
use Airygen\RabbitMQ\Support\DefaultExceptionClassifier;
use Airygen\RabbitMQ\Support\Stats;
use Airygen\RabbitMQ\Support\SystemClock;

// 測試用的 Payload 類別
class TestPayload extends ProducerPayload
{
    protected string $connectionName = 'default';
    protected ?string $exchangeName = 'amq.topic';  // 使用內建的 topic exchange
    protected ?string $routingKey = 'test.message'; // topic 格式的 routing key

    public function __construct(array $data)
    {
        parent::__construct($data);
    }
}

function printStats(string $stage): void
{
    $stats = Stats::snapshot();
    echo "\n=== $stage ===\n";
    echo "發送嘗試: " . $stats['publish_attempts'] . "\n";
    echo "重試次數: " . $stats['publish_retries'] . "\n";
    echo "發送失敗: " . $stats['publish_failures'] . "\n";
    echo "連線重置: " . $stats['connection_resets'] . "\n";
    echo "==================\n\n";
}

function waitForUserCheck(string $message): void
{
    echo $message . "\n";
    echo "請檢查 RabbitMQ 管理頁面 (http://localhost:15672) 的連線數和通道數\n";
    echo "按 Enter 繼續...";
    fgets(STDIN);
}

// RabbitMQ 配置
$config = [
    'connections' => [
        'default' => [
            'host' => 'localhost',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'options' => [
                'keepalive' => true,
                'heartbeat' => 60,
            ]
        ]
    ],
    'options' => [
    // Channel reuse has been removed; each publish opens/closes channel and connection
    ]
];

// 重試配置
$retryConfig = [
    'base_delay' => 0.2,
    'max_delay' => 1.5,
    'jitter' => true,
    'confirm_timeout' => 10.0,  // 10 秒確認超時
];

echo "RabbitMQ 連線重用測試\n";
echo "====================\n\n";

try {
    // 建立必要的實例（只建立一次）
    $connectionFactory = new ConnectionFactory();
    $connectionManager = new ConnectionManager($connectionFactory, $config);
    $messageFactory = new MessageFactory(new SystemClock(), 'test-app', 'testing');
    $classifier = new DefaultExceptionClassifier();
    
    // 建立 Publisher（只建立一次）
    $publisher = new Publisher(
        $connectionManager,
        $messageFactory,
        $classifier,
        null, // 不使用 logger
        $retryConfig
    );

    printStats("測試開始前");
    waitForUserCheck("準備開始測試，請先記錄 RabbitMQ 管理頁面的初始連線數和通道數");

    echo "開始發送 20 條訊息...\n";
    
    // 第一輪：發送 20 條訊息
    for ($i = 1; $i <= 20; $i++) {
        $payload = new TestPayload([
            'message_id' => $i,
            'content' => "Test message $i",
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        
        $publisher->publish($payload);
        
        if ($i % 5 === 0) {
            echo "已發送 $i 條訊息\n";
        }
    }

    printStats("第一輪發送完成");
    waitForUserCheck("第一輪發送完成！連線數應該只增加 1，通道數也應該只增加 1");

    echo "等待 3 秒後開始第二輪測試...\n";
    sleep(3);

    // 第二輪：再發送 30 條訊息
    echo "開始第二輪，發送 30 條訊息...\n";
    for ($i = 21; $i <= 50; $i++) {
        $payload = new TestPayload([
            'message_id' => $i,
            'content' => "Test message $i (round 2)",
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        
        $publisher->publish($payload);
        
        if ($i % 10 === 0) {
            echo "已發送 $i 條訊息\n";
        }
    }

    printStats("第二輪發送完成");
    waitForUserCheck("第二輪完成！連線數和通道數應該保持不變（重用）");

    // 批次發送測試
    echo "開始批次發送測試...\n";
    $batchPayloads = [];
    for ($i = 51; $i <= 70; $i++) {
        $batchPayloads[] = new TestPayload([
            'message_id' => $i,
            'content' => "Batch message $i",
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
    }
    
    $publisher->batchPublish($batchPayloads);
    echo "批次發送 20 條訊息完成\n";

    printStats("批次發送完成");
    waitForUserCheck("批次發送完成！連線數和通道數應該仍然保持不變");

    echo "\n測試完成！\n";
    echo "預期結果：\n";
    echo "- 連線數應該只增加 1\n";
    echo "- 通道數應該只增加 1\n";
    echo "- 連線重置應該為 0（如果沒有錯誤）\n";
    echo "- 總共發送了 70 條訊息\n\n";

    // 手動重置統計以便重複測試
    echo "是否要重置統計數據？(y/n): ";
    $input = trim(fgets(STDIN));
    if (strtolower($input) === 'y') {
        Stats::reset();
        echo "統計數據已重置\n";
    }

} catch (Exception $e) {
    echo "\n錯誤: " . $e->getMessage() . "\n";
    echo "堆疊追蹤:\n" . $e->getTraceAsString() . "\n";
    
    printStats("發生錯誤時");
} catch (Error $e) {
    echo "\n嚴重錯誤: " . $e->getMessage() . "\n";
    echo "堆疊追蹤:\n" . $e->getTraceAsString() . "\n";
}

echo "\n測試結束\n";