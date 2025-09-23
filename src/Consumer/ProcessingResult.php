<?php

declare(strict_types=1);

namespace Airygen\RabbitMQ\Consumer;

enum ProcessingResult: string
{
    case ACK = 'ack';
    case NACK_REQUEUE = 'nack_requeue';
    case NACK_DROP = 'nack_drop';
}
