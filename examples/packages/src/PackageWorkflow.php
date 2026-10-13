<?php

declare(strict_types=1);

namespace Survos\WorkflowExample;

use Composer\Semver\Semver;
use Survos\WorkflowAsyncBundle\Attribute\Transition as AsyncTransition;
use Survos\WorkflowExtrasBundle\Attribute\Place;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsWorkflow(name: 'package_readme', supports: Package::class, description: 'Fetch metadata and README for a Symfony 8 bundle')]
final readonly class PackageWorkflow
{
    #[Place('Discovered package', initial: true, next: [self::LOAD])]
    public const DISCOVERED = 'discovered';

    #[Place('Metadata fetched', next: [self::VALIDATE, self::REJECT])]
    public const LOADED = 'loaded';

    #[Place('Eligible Symfony 8 bundle', next: [self::FETCH_README])]
    public const VALID = 'valid';

    #[Place('Not eligible for this tutorial')]
    public const REJECTED = 'rejected';

    #[Place('README check completed')]
    public const README_CHECKED = 'readme_checked';

    #[AsyncTransition(self::DISCOVERED, self::LOADED, transport: 'package_metadata', description: 'Fetch release metadata from Packagist')]
    public const LOAD = 'load';

    #[Transition(self::LOADED, self::VALID, description: 'Validate package eligibility')]
    public const VALIDATE = 'validate';

    #[Transition(self::LOADED, self::REJECTED, description: 'Skip an ineligible package')]
    public const REJECT = 'reject';

    #[AsyncTransition(self::VALID, self::README_CHECKED, transport: 'package_readme', description: 'Fetch README at the selected release revision')]
    public const FETCH_README = 'fetch_readme';

    public function __construct(private HttpClientInterface $http)
    {
    }

    #[AsGuardListener]
    public function guard(GuardEvent $event): void
    {
        $eligible = $event->getSubject()->eligible;
        $name = $event->getTransition()->getName();
        if ((in_array($name, [self::VALIDATE, self::FETCH_README], true) && !$eligible) || ($name === self::REJECT && $eligible)) {
            $event->setBlocked(true, 'The selected package release does not match this transition.');
        }
    }

    #[AsTransitionListener(transition: self::LOAD)]
    public function load(TransitionEvent $event): void
    {
        /** @var Package $package */
        $package = $event->getSubject();
        $data = $this->http->request('GET', 'https://repo.packagist.org/p2/'.$package->name.'.json')->toArray();
        $versions = $data['packages'][$package->name] ?? [];
        // P2 is minified: expand before selecting the newest stable version.
        if (($data['minified'] ?? null) === 'composer/2.0') {
            $versions = \Composer\MetadataMinifier\MetadataMinifier::expand($versions);
        }
        foreach ($versions as $version) {
            if (!preg_match('/(?:dev|alpha|beta|RC)/i', $version['version'])) {
                $package->metadata = $version;
                break;
            }
        }
        $metadata = $package->metadata;
        $requires = $metadata['require'] ?? [];
        $symfony8 = false;
        $incompatibleCore = false;
        $coreComponents = ['symfony/framework-bundle', 'symfony/http-kernel', 'symfony/dependency-injection', 'symfony/config', 'symfony/console', 'symfony/event-dispatcher', 'symfony/workflow', 'symfony/cache', 'symfony/security-core'];
        foreach ($requires as $name => $constraint) {
            if (in_array($name, $coreComponents, true)) {
                $compatible = Semver::satisfies('8.2.0', $constraint);
                $symfony8 = $symfony8 || $compatible;
                $incompatibleCore = $incompatibleCore || !$compatible;
            }
        }
        $package->eligible = ($metadata['type'] ?? null) === 'symfony-bundle'
            && !($metadata['abandoned'] ?? false)
            && Semver::satisfies(PHP_VERSION, $requires['php'] ?? '*')
            && $symfony8 && !$incompatibleCore;
    }

    #[AsTransitionListener(transition: self::FETCH_README)]
    public function fetchReadme(TransitionEvent $event): void
    {
        /** @var Package $package */
        $package = $event->getSubject();
        $source = $package->metadata['source'] ?? [];
        if (!preg_match('{^https://github\.com/([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?$}D', $source['url'] ?? '', $match) || empty($source['reference'])) {
            $package->readmeStatus = 'unsupported_source';

            return;
        }
        $response = $this->http->request('GET', 'https://api.github.com/repos/'.$match[1].'/readme', [
            'query' => ['ref' => $source['reference']],
            'headers' => ['Accept' => 'application/vnd.github.raw+json', 'User-Agent' => 'Survos-Workflow-Example'],
        ]);
        if ($response->getStatusCode() === 404) {
            $package->readmeStatus = 'missing';

            return;
        }
        // Authentication/rate-limit/server errors throw and follow Messenger failure handling.
        $package->readme = $response->getContent();
        $package->readmeStatus = 'found';
    }
}
