### Security Vulnerabilities
If you discover a security vulnerability within Hackstra, report it privately to the project owner (Ankit Kumar, via GitHub) before publicly disclosing it. Do not include passwords, tokens, or private student data in a public issue.

### Supported versions
The `master` branch and the latest release receive security fixes. Older snapshots are not maintained.

### Security posture
- Role-based data scoping is enforced on the server for every role (a teacher sees only their own classes, a parent only their own children, the Super Admin is view-only inside schools).
- Security-relevant actions — logins, payments, deletions and mark changes (with old and new values) — are written to an audit log.
- Submitted attendance is permanently locked; published marks remain editable only by staff and every change is audit-logged.
- Online fee payments run through each school's own Razorpay account; Hackstra never holds fee money or card details.
