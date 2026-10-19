<?php
declare(strict_types=1);

namespace WPAICloudGateway\Scale;

/**
 * Pure, in-memory admission policy prototype.
 *
 * NOT process-safe, NOT distributed, NOT wired to WordPress/Gateway.
 * Production must replace the counters with an atomic storage adapter and
 * fenced leases; never use this class to enforce live tenant limits.
 */
final class AdmissionPolicy
{
    /** @var array<string,int> */
    private array $ownerActive = [];
    /** @var array<string,int> */
    private array $siteActive = [];
    private int $globalActive = 0;

    public function __construct(
        private readonly int $globalLimit,
        private readonly int $ownerLimit,
        private readonly int $siteLimit
    ) {
        if (min($globalLimit, $ownerLimit, $siteLimit) < 1) {
            throw new \InvalidArgumentException('Limits must be positive');
        }
    }

    /** @return bool True only when ALL ceilings have capacity. */
    public function tryAcquire(string $verifiedOwner, string $authorizedSiteId): bool
    {
        self::validateIdentity($verifiedOwner, $authorizedSiteId);
        if ($this->globalActive >= $this->globalLimit ||
            ($this->ownerActive[$verifiedOwner] ?? 0) >= $this->ownerLimit ||
            ($this->siteActive[$authorizedSiteId] ?? 0) >= $this->siteLimit) {
            return false;
        }

        ++$this->globalActive;
        $this->ownerActive[$verifiedOwner] = ($this->ownerActive[$verifiedOwner] ?? 0) + 1;
        $this->siteActive[$authorizedSiteId] = ($this->siteActive[$authorizedSiteId] ?? 0) + 1;
        return true;
    }

    /** Release must correspond to a successful acquire. */
    public function release(string $verifiedOwner, string $authorizedSiteId): void
    {
        self::validateIdentity($verifiedOwner, $authorizedSiteId);
        if ($this->globalActive === 0 ||
            ($this->ownerActive[$verifiedOwner] ?? 0) === 0 ||
            ($this->siteActive[$authorizedSiteId] ?? 0) === 0) {
            throw new \LogicException('Unmatched admission release');
        }

        --$this->globalActive;
        if (--$this->ownerActive[$verifiedOwner] === 0) unset($this->ownerActive[$verifiedOwner]);
        if (--$this->siteActive[$authorizedSiteId] === 0) unset($this->siteActive[$authorizedSiteId]);
    }

    /** @return array{global:int,owner:int,site:int} */
    public function active(string $verifiedOwner, string $authorizedSiteId): array
    {
        self::validateIdentity($verifiedOwner, $authorizedSiteId);
        return [
            'global' => $this->globalActive,
            'owner' => $this->ownerActive[$verifiedOwner] ?? 0,
            'site' => $this->siteActive[$authorizedSiteId] ?? 0,
        ];
    }

    private static function validateIdentity(string $owner, string $site): void
    {
        if ($owner === '' || $site === '' || strlen($owner) > 128 || strlen($site) > 128) {
            throw new \InvalidArgumentException('Server-verified owner and site required');
        }
    }
}
