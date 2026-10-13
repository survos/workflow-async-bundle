# Workflow Async Bundle

Queue **native Symfony 8.2 workflow transitions** through Messenger. Symfony owns
workflow definitions, guards, marking and events. This bundle supplies an explicit
dispatcher and a worker handler; your ordinary transition listeners do the work.

**Experimental 0.1 scaffold.** Requires PHP 8.5 (our kit-bundle baseline) and
`8.2.*@dev` for its Symfony integration dependencies. No stable release or
production migration is promised yet. The example lockfile records tested source
revisions; existing applications are not upgraded by this repository change.

## Why a separate bundle?

[Symfony #61935](https://github.com/symfony/symfony/pull/61935) introduced native
workflow attributes. [#66722](https://github.com/symfony/symfony/pull/66722) added
`Place(initial: true)`, and [#66687](https://github.com/symfony/symfony/pull/66687)
made `AsWorkflow`, `Place` and `Transition` extensible, and
[#66872](https://github.com/symfony/symfony/pull/66872) gave them a native
`description` argument, which [#66890](https://github.com/symfony/symfony/pull/66890)
made the third `Transition` argument. All are merged into 8.2. This package builds on that
work instead of maintaining another definition reader or registrar.

You can use native attributes with metadata, or the optional typed attribute:

```php
use Survos\WorkflowAsyncBundle\Attribute\Transition;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Place;

#[AsWorkflow(
    name: 'packages',
    supports: Package::class,
    description: 'Load package metadata from Packagist',
)]
final class PackageWorkflow
{
    #[Place('Package discovered; metadata not yet loaded', initial: true)]
    public const DISCOVERED = 'discovered';

    #[Place('Package metadata loaded')]
    public const LOADED = 'loaded';

    #[Transition(
        self::DISCOVERED,
        self::LOADED,
        description: 'Fetch package metadata from Packagist',
        transport: 'package_metadata',
    )]
    public const LOAD = 'load';
}
```

Our `Transition` defaults to `async: true`. Native arguments (`from`, `to`, `guard`,
`description`, `metadata`) retain their types and order, including enum cases and weighted `Arc`
values. It is repeatable. The equivalent native declaration is:

```php
#[\Symfony\Component\Workflow\Attribute\Transition(
    from: self::DISCOVERED,
    to: self::LOADED,
    description: 'Fetch package metadata from Packagist',
    metadata: [
        'async' => true,
        'transport' => 'package_metadata',
    ],
)]
```

Both endpoints reference declared place constants: changing a place's stored value
requires editing only its declaration. We explicitly declare and describe every
place and transition instead of relying on inferred places. That gives humans
and tools a complete, documented definition. Native backed enum cases work too;
enums are optional.

That equivalence also lets workflow-extras attributes opt into async without a
mandatory dependency between the two bundles. Use one transition attribute for
each transition definition, rather than stacking competing descriptions of it.
Typed options reject conflicting metadata; `async: false` cannot specify a
transport. For repeated definitions of one transition name, async intent and the
resolved transport must agree. Routing is validated when dispatching/handling.

## Install in an experimental 8.2 application

After the split repositories have been published and registered on Packagist:

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require 'survos/workflow-async-bundle:dev-main' --with-all-dependencies --no-scripts
```

An existing application's Symfony constraints (including Flex's `extra.symfony.require`)
must allow 8.2 first. Review its dependency changes and commit its lockfile. Do not
expect a library's `minimum-stability` setting to override the application's.
Pin a development revision containing #66687; an older cached 8.2 lockfile can
still have final attributes. Move to a containing 8.2 release when available.

Enable `Survos\WorkflowAsyncBundle\SurvosWorkflowAsyncBundle` if Flex has not done
so. Its required bundles provide Workflow, Messenger and Survos Kit. The tested
minimal kernel runs **without FrameworkBundle, Doctrine ORM or Twig installed**;
kit still depends on HttpKernel.

Implement [SubjectStoreInterface](src/Contract/SubjectStoreInterface.php) and
register it as a service. A reference contains a stable domain `type` and string
`id`. The adapter validates the type, loads a fresh subject and saves successful
changes. Its `save(SubjectReference $reference, object $subject, array $context = [])`
method receives the original apply context so application-owned continuation policy
can honor options such as `cascade: none`. A plain object with a `marking` property works; Doctrine is optional.
For a Doctrine application, use the entity's own manager, and define the
transaction/locking policy explicitly rather than flushing unrelated work.

```yaml
# config/packages/survos_workflow_async.yaml
survos_workflow_async:
    subject_store: App\Workflow\PackageStore
    default_transport: package_metadata # optional when every transition specifies one

# config/packages/messenger.yaml -- component-owned configuration in Symfony 8.2
messenger:
    transports:
        package_metadata: '%env(METADATA_TRANSPORT_DSN)%'
        package_readme: '%env(README_TRANSPORT_DSN)%'
```

Use genuine consumable transports such as configured Doctrine, Redis or AMQP
transports. Their transport packages, queue options and failure/retry policies
belong to the application. Sync and failure transports are excluded from the
bundle's selectable routes. Automatic queue provisioning is not implemented.
Configure the adapter before running application cache/warmup scripts.

## Dispatch, then execute in a worker

Persist **and commit** the subject before enqueueing:

```php
$dispatcher->dispatch(
    'packages',
    new \Survos\WorkflowAsyncBundle\Message\SubjectReference('package', $packageId),
    PackageWorkflow::LOAD,
    context: ['requested_by' => 'import'],
);
```

Inject [TransitionDispatcher](src/Service/TransitionDispatcher.php) normally.
The request contains workflow name, subject reference, transition name, plain-data
context, request ID and schema version. It is routed with `TransportNamesStamp`;
no message-class routing rule is required. The selected Messenger bus must retain
its normal send/handle middleware. Native workflow services are found by their
`workflow` tags, so injection-only workflows need not belong to the Registry.

```bash
php bin/console messenger:consume package_metadata package_readme
```

The worker loads the subject, calls native `apply()` with the supplied context,
and saves after successful execution. Standard `AsTransitionListener` listeners
run in that worker. Calling native `apply()` yourself remains synchronous even
if async metadata is present; this bundle never intercepts it.

Handler results report `applied`, `blocked` (including blocker messages) or
`missing`. They are available through Messenger's `HandledStamp`, including to
worker event subscribers. Blocked and missing requests are acknowledged rather
than retried. Configuration errors are unrecoverable; listener, network and
persistence failures propagate to Messenger's configured failure handling.
The scaffold does not yet supply a metrics/logging subscriber.

## Delivery and persistence boundaries

- Delivery is at least once. A request ID supports tracing, not deduplication or
  exactly-once execution. External side effects must be idempotent.
- Guards run in the worker against current state. A stale request can be blocked;
  a self-transition may still be enabled on redelivery.
- The adapter owns persistence. This scaffold does not provide a Doctrine adapter,
  transaction middleware, locks or an outbox. Concurrent consumers of one subject
  need an application-level concurrency strategy. Retried work needs a fresh
  persistence context, particularly after an ORM failure.
- A database flush is not necessarily a commit. Queuing inside an open producer
  transaction can let a worker run before it sees the subject.
- Context permits only arrays, scalars and null, not entities, closures or events.

## Run the packages example

The [small executable example](examples/packages/README.md) adapts our packages
application: fetch Packagist metadata, perform cheap validation synchronously,
then fetch the README of an eligible Symfony 8 bundle on a second queue.

From a **mono checkout**, with PHP 8.5:

```bash
cd bu/workflow-async-bundle/examples/packages
composer install --no-plugins --no-scripts
php demo.php --offline
# Or a real package, using Packagist and GitHub:
php demo.php symfony/monolog-bundle
composer test
```

The example uses local path repositories for both bundles and kit, a locked 8.2
dependency set, a JSON subject store, serialized in-memory transports and real
Messenger Workers. It is a bounded, single-process demonstration, not a durable
broker deployment. No network is used by its tests or offline mode.

## What is here / what comes next

Implemented: typed async transition metadata, explicit dispatch, named native
workflow lookup, transport selection, subject-store contract, handler outcomes,
real-worker example and tests.

Next: application-specific Doctrine integration in packages, durable transport
and retry/failure integration tests, optional transport provisioning, then
searchbench and harvest validation. No production claim is made for these yet.

Workflow descriptions, `next` selection and future iteration/UI belong to
[workflow-extras-bundle](https://github.com/survos/mono/tree/main/bu/workflow-extras-bundle).
The legacy [state-bundle](https://github.com/survos/state-bundle) remains supported;
do not enable two registrars for the same workflow or discard old queued message
classes during migration.
