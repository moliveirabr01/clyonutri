<?php
declare(strict_types=1);
namespace ClyoNutri\Application\View;
/** Recovery is scoped to a failed form in the current response; passwords are never retained. */
final class SettingsFormRecovery
{
    public function __construct(private bool $failed,private array $posted){}
    public function value(string $section,string $name,mixed $saved): mixed
    {
        if(!$this->failed||($this->posted['section']??null)!==$section||preg_match('/password|csrf|revision/i',$name))return $saved;
        return is_string($this->posted[$name]??null)?$this->posted[$name]:$saved;
    }
    public function checked(string $section,string $name,bool $saved): bool
    {
        return $this->failed&&($this->posted['section']??null)===$section?!empty($this->posted[$name]):$saved;
    }
}
