// Tester scenario: visitor attribution + UTM capture survives a form submit.
// The TESTER worker visits a page with UTM params, fills the form, and
// confirms the resulting submission row carries the original visitor id,
// utm_source, utm_medium, and utm_campaign.

export const meta = {
    slug: 'formflow.attribution-pixel',
    description: 'Confirm visitor + UTM attribution survives the form submit to the connector payload.',
    persona: 'utility-customer',
    cost: 'cheap',
};

export default meta;
