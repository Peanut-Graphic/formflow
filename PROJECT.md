# FormFlow Pro project starting point

Provide white-label multi-step enrollment and scheduling workflows with modular backend connectors.

## Direction

Documented intent: Provide white-label multi-step enrollment and scheduling workflows with modular backend connectors.

Owner confirmation needed: ultimate scope, launch or completion acceptance criteria, and the next priority. This brief does not mark any feature or project complete.

## Source baseline

- Inspected: 2026-10-09
- Local branch: `fix/confirmation-time-label-and-demo-account`
- Local HEAD: `cdb2c5b96db4917be9f921aa5349bad426ee106e`
- Lifecycle registry: `active`; classification: `distributed-plugin` (registry reviewed 2026-08-29; current lifecycle not independently confirmed).
- Working tree at inspection: contains local changes.

## What it is built with

- package.json: Node tooling; engines {"node": "22.22.2", "npm": "10.9.7"}
- composer.json: php >=8.2
- formflow.php: WordPress plugin FormFlow; WordPress 6.0; PHP 8.2

## Build and verification entry points

These are source-declared entry points, not commands executed by this audit. Install dependencies and configure a local environment according to the linked setup documentation before running them. Release/hosting actions remain subject to the project’s deployment rules.

- package.json: `npm test`
- package.json: `npm run runtime:verify`
- composer.json: `composer test`

## Structure and relationships

- `public/` is present in this checkout.
- `tests/` is present in this checkout.
- Cross-project integration details belong in the linked architecture and API documents; folder similarity alone does not establish a dependency.

## Gaps to resolve

- Working tree has uncommitted changes; this baseline includes local declarations beyond the recorded HEAD.
- Owner acceptance criteria and current priority order are not yet recorded in this brief.

## Source documents

- [README.md](README.md)
- [ROADMAP.md](ROADMAP.md)
- [docs/](docs/)

## Keeping this current

Update this starting point when product direction, architecture, dependencies, or build/release entry points change. Recheck source facts against the current checkout. Date stamps describe inspection, not live production verification.

<!-- dino-source-audit {"date":"2026-10-09","head":"cdb2c5b96db4917be9f921aa5349bad426ee106e","sources":{"README.md":"8b051a684bc1bd8f222a4eb7e60043765463469d4022a05b6c91e392a0d46c9c","package.json":"afae34db17b2430bde38b987ff9b863f109fafd04f77a01634d6c842ea6cbd70","composer.json":"55596901ff132604c6ecd96506ed9b5e6033f400acb3aea9da843b746905cfdd",".nvmrc":"4c42fb8d6334c5cdcac68b93f96c581fb83b1f58cda898cff115e5e941ef717d","formflow.php":"c9fb188e0eb091f86b7a90be636c3993640747e457fdee656f99843c527ea0da","ROADMAP.md":"bb17431d57798d872bb4969e7ab64cf4879381289f3fe248ce1622f46d0c4e8b"}} -->
