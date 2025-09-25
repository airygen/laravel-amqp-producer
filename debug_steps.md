# 連線數增長診斷步驟

## 1. 確認你看的數據是什麼

RabbitMQ 管理後台有幾個不同的指標：

- **Total connections**：歷史累積總數（包括已關閉的）
- **Current connections**：當前活躍連線數
- **Connection rate**：每秒建立的連線數

**請確認你看的是 "Current connections" 而不是累積總數。**

## 2. 檢查測試程式碼

```php
// 建立一個測試腳本
$producer = app(ProducerInterface::class);

echo "開始測試前的連線數：檢查 RabbitMQ 管理頁面\n";
sleep(5);

for ($i = 0; $i < 50; $i++) {
    $payload = new YourPayload(['data' => $i]);
    $producer->publish($payload);
    
    if ($i % 10 === 0) {
        echo "已發送 $i 條訊息\n";
        sleep(1); // 讓你有時間檢查管理頁面
    }
}

echo "測試完成，再次檢查連線數\n";
sleep(10); // 給 RabbitMQ 時間回收連線
```

## 3. 檢查配置

確認你的配置中：

```php
'options' => [
    // Channel reuse has been removed; behavior is always open/close per call
]
```

## 4. 檢查是否有並發測試

如果你在多個終端機或多個 process 同時測試，每個 process 都會有自己的連線。

## 5. 檢查 Laravel 快取驅動

如果你使用了 Laravel 的快取或隊列，確認沒有其他地方也在建立 RabbitMQ 連線。

## 6. 監控實際連線狀態

在你的測試程式中加入：

```php
use Airygen\RabbitMQ\Support\Stats;

$stats = Stats::snapshot();
echo "Connection resets: " . $stats['connection_resets'] . "\n";
echo "Publish attempts: " . $stats['publish_attempts'] . "\n";
```

如果 `connection_resets` 大於 0，說明連線被重置了。