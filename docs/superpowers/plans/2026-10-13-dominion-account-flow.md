# Account-based Dominion enrollment preparation

The customer confirmed the existing account-based Portal API is the target,
OTP is not required, and no password is created at enrollment. A QA environment
is not available. Do not infer BYOT-only enrollment from the absence of devices.

`AccountEnrollmentFlow` is a server-only request planner with no HTTP transport,
no AJAX registration, and no connection to the live form. It establishes the
observed ordering: vendor validation → selected premise → portal session →
qualification/terms → enrollment model → explicit vendor success. Session auth
uses X-Enrollment-Token, X-PortalUser-Id, and X-PortalUser-Premise, as the current
SPA does. Raw tokens and plans must never be sent to the browser or logged.

Mock tests exercise this sequence, required terms, server-supplied program data,
failed replies and invalidation after edits. They are not vendor acceptance
proof. The planner does not make enrollment live: submit_enrollment still
returns not_implemented outside demo mode. The final PUT plan is flagged as
requiring vendor confirmation. Actual portal responses, conditional survey
rules, program survey selection, existing-account login, session expiry,
transport/cookie requirements, and safe retry handling still need integration.

The HUB prefill backend is on codex/dominion-prefill. HubProspectClient provides a server-to-server site adapter using the existing
HUB/Connect connection, HTTPS/SSRF checks and redirects disabled. HUB must
explicitly opt the site in to the sensitive lookup. The adapter is not registered
as an AJAX handler; its raw account response stays server-side. Next, wire it
into the enrollment handler with an expiring session-bound reference,
followed by a controlled enrollment with a consenting live account after Itron
confirms the remaining contract. User account/ZIP corrections must invalidate
and revalidate; vendor name/service address remain authoritative. Never mark
local collection as vendor enrollment success.
