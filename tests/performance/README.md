# Classroom capacity reference

`seed.php` creates only synthetic accounts, encrypted database sessions and 20 classrooms. It refuses production environments and database names. `load.py` accepts loopback URLs only; it generates 40 concurrent authenticated clients polling at the same 1.5-second interval as the classroom for 20 rounds. A warm-up refreshes presence and caches before measurement. Responses must contain both participants. The deployment job requires zero errors and p95 below 1.5 seconds on isolated MySQL CI.

This measures application signalling/API traffic, not 40 real camera streams, and does not certify the capacity of shared IONOS hosting. The separate two-browser test covers actual WebRTC media and TURN recovery. Never run synthetic load against production or seed production data. Aggregate reports exclude session cookies and identities. Actual production capacity also depends on PHP worker limits, database quotas, participant bandwidth and TURN traffic.

Baseline fixture (500 ERP students, 30 online courses, one enrolled course): 60 database queries per authenticated state request before optimization, 15 after. Authorization revocation and corrupt-source rejection remain covered by `CampusPerformanceTest`.
