<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\FoodDiary;

/** NI2-132: a mesma Idempotency-Key foi reenviada com conteúdo diferente (HTTP 409). */
final class IdempotencyKeyReusedException extends \RuntimeException
{
}
