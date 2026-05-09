// Tester scenario: license activation + tier-gated feature access.
// Activates against the Peanut License Server, verifies the response surfaces
// the right tier, then hits a Pro-only endpoint and asserts gating works.

export const meta = {
    slug: 'formflow.license-check',
    description: 'Activate a license and confirm tier-gated features unlock + lock as expected.',
    persona: 'admin',
    cost: 'cheap',
};

export default meta;
