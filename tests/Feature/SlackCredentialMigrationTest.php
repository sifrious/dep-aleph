<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sifrious\Aleph\Connector\ConnectorInstallations;
use Sifrious\Aleph\Connector\Slack\LandingSlackTokenRecord;
use Sifrious\Aleph\Connector\Slack\SlackCredentialBroker;
use Sifrious\Aleph\Connector\Slack\SlackCredentialFailure;
use Sifrious\Aleph\Connector\Slack\SlackCredentials;
use Sifrious\Aleph\Connector\Slack\SlackCredentialState;
use Sifrious\Aleph\Connector\Slack\SlackSecretRotation;
use Sifrious\Aleph\Connector\Slack\SlackSecretStore;
use Sifrious\Aleph\Connector\Slack\SlackTokenSecret;
use Sifrious\Aleph\Testing\Fakes\DiscoveryAndDownloadConnector;

final class FixtureSlackSecretStore implements SlackSecretStore
{
    /** @var array<string, string> */
    public array $values;

    /** @var list<string> */
    public array $resolved = [];

    /** @var list<string> */
    public array $refreshed = [];

    /** @var list<string> */
    public array $revoked = [];

    /** @param array<string, string> $values */
    public function __construct(array $values = [], private readonly ?SlackSecretRotation $rotation = null)
    {
        $this->values = $values;
    }

    public function resolve(string $reference): ?SlackTokenSecret
    {
        $this->resolved[] = $reference;

        return isset($this->values[$reference]) ? new SlackTokenSecret($this->values[$reference]) : null;
    }

    public function refresh(string $reference): SlackSecretRotation
    {
        $this->refreshed[] = $reference;

        return $this->rotation ?? throw new RuntimeException('No Slack rotation fixture is configured.');
    }

    public function revoke(string $reference): void
    {
        $this->revoked[] = $reference;
        unset($this->values[$reference]);
    }
}

function slackInstallation(string $workspace = 'slack:workspace/T123'): object
{
    return app(ConnectorInstallations::class)->create(
        new DiscoveryAndDownloadConnector,
        $workspace,
        externalAccountId: $workspace,
        funesSourceAccountId: 'source-account:'.$workspace,
    );
}

function landingSlackToken(
    object $installation,
    ?string $secretReference = 'secret://slack/T123/user/U456',
    ?DateTimeImmutable $expiresAt = null,
    ?DateTimeImmutable $revokedAt = null,
    array $scopes = ['channels:history', 'users:read'],
): LandingSlackTokenRecord {
    return new LandingSlackTokenRecord(
        '42',
        $installation->id,
        'slack:workspace/T123',
        'slack:user/U456',
        $secretReference,
        $scopes,
        $expiresAt ?? new DateTimeImmutable('2026-08-29T00:00:00+00:00'),
        $revokedAt,
        new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
        new DateTimeImmutable('2026-06-14T12:00:00+00:00'),
        new DateTimeImmutable('2026-08-28T10:00:00+00:00'),
    );
}

it('migrates only workspace account scope state and opaque secret metadata', function (): void {
    $credential = app(SlackCredentials::class)->migrate(landingSlackToken(slackInstallation()));
    $portable = json_encode([
        DB::table('aleph_slack_credentials')->get()->all(),
        $credential->metadata(new DateTimeImmutable('2026-08-28T12:00:00+00:00')),
    ], JSON_THROW_ON_ERROR);

    expect($credential->workspaceReference)->toBe('slack:workspace/T123')
        ->and($credential->accountReference)->toBe('slack:user/U456')
        ->and($credential->scopes)->toBe(['channels:history', 'users:read'])
        ->and($credential->stateAt(new DateTimeImmutable('2026-08-28T12:00:00+00:00')))->toBe(SlackCredentialState::Active)
        ->and($credential->secretReference)->toBe('secret://slack/T123/user/U456')
        ->and($portable)->not->toContain('xoxp-', 'xoxe-', 'access_token', 'refresh_token')
        ->and(DB::table('funes_observations')->count())->toBe(0);
});

it('resolves and rotates Slack secrets only through the host secret store', function (): void {
    $installation = slackInstallation();
    $credential = app(SlackCredentials::class)->migrate(landingSlackToken($installation));
    $rotation = new SlackSecretRotation(
        new SlackTokenSecret('xoxp-rotated'),
        new DateTimeImmutable('2026-08-30T00:00:00+00:00'),
        ['channels:history', 'users:read', 'files:read'],
    );
    $store = new FixtureSlackSecretStore([$credential->secretReference => 'xoxp-live'], $rotation);
    $broker = new SlackCredentialBroker(app(SlackCredentials::class), $store);
    $active = $broker->accessToken($installation->id, new DateTimeImmutable('2026-08-28T12:00:00+00:00'));
    $rotated = $broker->refresh($installation->id, new DateTimeImmutable('2026-08-28T13:00:00+00:00'));
    $metadata = app(SlackCredentials::class)->forInstallation($installation->id)?->metadata(new DateTimeImmutable('2026-08-28T13:00:00+00:00'));

    expect($active->reveal())->toBe('xoxp-live')
        ->and($rotated->reveal())->toBe('xoxp-rotated')
        ->and($store->resolved)->toBe(['secret://slack/T123/user/U456'])
        ->and($store->refreshed)->toBe(['secret://slack/T123/user/U456'])
        ->and($metadata['scopes'])->toBe(['channels:history', 'users:read', 'files:read'])
        ->and($metadata['state'])->toBe('active')
        ->and(json_encode($metadata, JSON_THROW_ON_ERROR))->not->toContain('xoxp-live', 'xoxp-rotated');
});

it('reports missing expired and revoked credentials explicitly without secret values', function (string $expected, ?string $reference, string $expiry, ?string $revoked): void {
    $installation = slackInstallation();
    $credential = app(SlackCredentials::class)->migrate(landingSlackToken(
        $installation,
        $reference,
        new DateTimeImmutable($expiry),
        $revoked === null ? null : new DateTimeImmutable($revoked),
    ));
    $broker = new SlackCredentialBroker(app(SlackCredentials::class), new FixtureSlackSecretStore);

    try {
        $broker->accessToken($installation->id, new DateTimeImmutable('2026-08-28T12:00:00+00:00'));
        $failure = null;
    } catch (SlackCredentialFailure $caught) {
        $failure = $caught;
    }

    expect($credential->stateAt(new DateTimeImmutable('2026-08-28T12:00:00+00:00'))->value)->toBe($expected)
        ->and($failure?->state->value)->toBe($expected)
        ->and($failure?->getMessage())->not->toContain('secret://', 'xoxp-', 'xoxe-');
})->with([
    'missing' => ['missing', null, '2026-08-29T00:00:00+00:00', null],
    'expired' => ['expired', 'secret://slack/T123/user/U456', '2026-08-28T11:00:00+00:00', null],
    'revoked' => ['revoked', 'secret://slack/T123/user/U456', '2026-08-29T00:00:00+00:00', '2026-08-28T11:30:00+00:00'],
]);

it('revokes through the secret store before recording portable revocation state', function (): void {
    $installation = slackInstallation();
    $credential = app(SlackCredentials::class)->migrate(landingSlackToken($installation));
    $store = new FixtureSlackSecretStore([$credential->secretReference => 'xoxp-live']);
    $broker = new SlackCredentialBroker(app(SlackCredentials::class), $store);
    $at = new DateTimeImmutable('2026-08-28T12:00:00+00:00');
    $broker->revoke($installation->id, $at);
    $stored = app(SlackCredentials::class)->forInstallation($installation->id);

    expect($store->revoked)->toBe(['secret://slack/T123/user/U456'])
        ->and($stored?->stateAt($at))->toBe(SlackCredentialState::Revoked)
        ->and($stored?->revokedAt?->format(DATE_ATOM))->toBe($at->format(DATE_ATOM));
});

it('replays migration and leaves the legacy token copy for post parity cutover', function (): void {
    Schema::create('slack_tokens', function (Blueprint $table): void {
        $table->id();
        $table->text('access_token');
        $table->text('refresh_token')->nullable();
        $table->timestamp('expires_at')->nullable();
    });
    DB::table('slack_tokens')->insert([
        'id' => 42,
        'access_token' => 'encrypted-legacy-access',
        'refresh_token' => 'encrypted-legacy-refresh',
        'expires_at' => '2026-08-29 00:00:00',
    ]);
    $record = landingSlackToken(slackInstallation());
    $first = app(SlackCredentials::class)->migrate($record);
    $replay = app(SlackCredentials::class)->migrate($record);

    expect($replay->id)->toBe($first->id)
        ->and(DB::table('aleph_slack_credentials')->count())->toBe(1)
        ->and(Schema::hasTable('slack_tokens'))->toBeTrue()
        ->and(DB::table('slack_tokens')->where('id', 42)->exists())->toBeTrue();
});
