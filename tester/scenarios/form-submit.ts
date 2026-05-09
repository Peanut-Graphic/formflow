// Tester scenario: end-to-end form submission against a configured connector.
// The TESTER worker fills a form, posts it through the wp-admin-ajax bridge,
// and asserts the submission lands in the connector's downstream queue (or
// the local fallback table) plus the user-facing thank-you page renders.

export const meta = {
    slug: 'formflow.form-submit',
    description: 'Submit a fully-populated form and confirm it reaches the configured connector.',
    persona: 'utility-customer',
    cost: 'cheap',
};

export default meta;
