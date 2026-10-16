<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Telehealth;

use ClyoNutri\Domain\Telehealth\Contracts\VideoConferenceProviderInterface;
use ClyoNutri\Domain\Telehealth\Providers\DailyVideoProvider;
use ClyoNutri\Domain\Telehealth\Providers\PeerToPeerWebRtcProvider;
use PDO;

/**
 * Seleciona o provedor de mídia por TELECONSULT_PROVIDER:
 * - webrtc_p2p (padrão): WebRTC nativo, gratuito, sinalização no backend.
 * - daily_co: Daily (pago), mantido como alternativa.
 */
final class TeleconsultationProviderFactory
{
    public static function make(PDO $db): VideoConferenceProviderInterface
    {
        $choice = getenv('TELECONSULT_PROVIDER') ?: ($_ENV['TELECONSULT_PROVIDER'] ?? 'webrtc_p2p');
        return match ((string)$choice) {
            'daily_co' => new DailyVideoProvider(),
            default => new PeerToPeerWebRtcProvider($db),
        };
    }
}
