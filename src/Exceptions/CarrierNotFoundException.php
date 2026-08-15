<?php

declare(strict_types=1);

namespace GlobalLogistics\Exceptions;

class CarrierNotFoundException extends LogisticsException
{
    public function __construct(
        private readonly string $carrierCode,
        ?string $message = null,
    ) {
        parent::__construct($message ?? sprintf('Carrier "%s" not found or cannot be auto-detected', $carrierCode));
    }

    public function getCarrierCode(): string
    {
        return $this->carrierCode;
    }
}
