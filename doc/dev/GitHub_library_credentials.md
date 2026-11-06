# Draft: per-user GitHub library credentials

This branch builds on #1948's CLI-only environment token. It prototypes manually entered, read-only
personal access tokens for administrator-configured GitHub libraries. It does not add OAuth or an
institutional service account.

A teacher with the existing question-add and external-library capabilities can enter a token on the
library page. POST and Moodle's session key protect token replacement and removal. The token stays in
Moodle's server-side session, bound to the Moodle user and the configured repository URL. It expires
one hour after entry or at logout. This is a proposed lifetime for review, not a Moodle default.
Session storage can reside in a database, files or Redis and is accessible to the server operator.
The token is never returned in the form or passed through the preview/import JavaScript.

Replacing or forgetting a token, changing the repository configuration, or expiring the connection
invalidates its random browser handle. Old tabs must reopen the library. A different Moodle user
cannot reuse a handle, including when the same session object is retained across an identity change.
Credential use during Moodle's "Log in as" mode is rejected.

GitHub listings and public rendered previews use a separate session cache. Listings are fetched on
each page visit. Preview keys include the blob URL and current language. Preview and import requests
fetch the file using the current credential; a cached render cannot bypass a GitHub access failure.
File URLs must come from that session's listing and match the configured repository's blob endpoint.
There is no fallback to the server environment token in a browser request.

## Deliberate draft boundary

Authenticated libraries show escaped XML/JSON source, with no CAS evaluation or embedded assets.
Import is available and creates ordinary Moodle question-bank content under the destination's
permissions. Forgetting a token does not delete content already imported.

Full rendered private previews are **not implemented**. Their plots, attachments and CAS-derived data
must avoid shared output stores from the start of generation, including both local Maxima and remote
CAS connectors. Rewriting the final HTML URLs would not meet that requirement. The draft therefore
never calls the existing question loader/renderer for authenticated previews, even for a public
repository accessed with a token. No private preview files are created by that path.

Before proposing this branch for merge:

- agree PAT versus OAuth/GitHub App UX, and whether the one-hour session policy is appropriate;
- implement and test protected rendered previews, or explicitly agree source-only previews as the scope;
- complete privacy metadata and session-data handling review;
- exercise the form in a real browser, including failed submissions and permission changes;
- exercise real GitHub token revocation and repository-access changes;
- review partial failures during multi-question imports and credential replacement during in-flight requests;
- run the supported Moodle CI matrix and decide the plugin version/cache-definition upgrade.

A site credential is a separate design decision: it could support public-library API quotas or an
institutional private library with an explicitly authorised Moodle audience. It must not silently
substitute for a user's rejected credential.
