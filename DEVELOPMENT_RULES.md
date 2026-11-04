# CoreAxis CRM Development Rules

These rules protect the currently running application while allowing isolated, reviewable development.

1. Protect the currently running application.
2. Never experiment directly on the working database.
3. Use isolated development and test environments.
4. Verify the relevant working baseline before changes.
5. Implement one coherent sprint objective at a time.
6. Avoid unrelated refactoring unless essential.
7. Preserve login, authorization, attendance, leave, and payroll safeguards.
8. Require appropriate regression testing before integration.
9. Use browser-based verification for user-facing changes.
10. Never claim browser tests passed unless actually executed.
11. Never enable unsafe payroll generation or payment actions.
12. Never destroy historical HR/payroll records.
13. Use version control and review before promotion.
14. Maintain a tested rollback path.
15. Update project state after each verified sprint.
16. Do not push broken or unverified changes.

## Sprint execution standard

1. Verify the protected baseline.
2. Define one coherent implementation objective.
3. Implement in an isolated development workspace.
4. Run static and database tests.
5. Run browser/end-to-end tests.
6. Fix discovered regressions.
7. Review security and data integrity.
8. Create a recoverable integration checkpoint.
9. Promote only verified changes.
10. Run post-promotion smoke tests.
11. Roll back if critical checks fail.
12. Update documentation and Git state.

Use risk-based test coverage; do not rerun unrelated expensive tests unnecessarily during development.
