// QA fixture: a guard whose evaluator always throws. Used to prove runGuard fails closed end to end.
import { runGuard } from '../../../.claude/hooks/lib/hook-io.mjs';

await runGuard(() => {
  throw new Error('evaluator exploded');
});
