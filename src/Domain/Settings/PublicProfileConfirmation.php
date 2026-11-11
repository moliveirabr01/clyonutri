<?php
declare(strict_types=1);
namespace ClyoNutri\Domain\Settings;

/** Explicit approval of the exact public text; clinic-scoped preferences are the persisted source. */
final class PublicProfileConfirmation
{
    public static function digest(array $profile): string
    {
        return hash('sha256', json_encode([
            'version'=>1,
            'specialty'=>trim((string)($profile['specialty'] ?? '')),
            'bio'=>trim((string)($profile['bio'] ?? '')),
        ], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    public static function isConfirmed(array $profile, array $preferences): bool
    {
        $approval = $preferences['public_profile_confirmation'] ?? null;
        return is_array($approval) && !empty($approval['confirmed_at'])
            && is_string($approval['digest'] ?? null)
            && hash_equals(self::digest($profile), $approval['digest']);
    }

    public static function revise(array $preferences, array $profile, array $input, int $author): array
    {
        $action = $input['public_profile_action'] ?? 'keep';
        if (!is_string($action) || !in_array($action, ['keep','publish','hide'], true)) {
            throw new \InvalidArgumentException('Escolha uma ação válida para a apresentação pública.');
        }
        if ($action === 'hide' || !self::isConfirmed($profile, $preferences)) {
            unset($preferences['public_profile_confirmation']);
        }
        if ($action === 'publish') {
            if (($input['confirm_public_profile'] ?? '') !== '1') {
                throw new \InvalidArgumentException('Revise a prévia e confirme a publicação da especialidade e apresentação.');
            }
            $preferences['public_profile_confirmation'] = [
                'digest'=>self::digest($profile), 'confirmed_at'=>gmdate('c'), 'confirmed_by'=>$author,
            ];
        }
        return $preferences;
    }
}
