// Flow: chat in → flow → reply out. 10, 20, 30, 50 and 100 chats per second for 30 s each, from a pool of 1,000
// customers (so the same customers come back and the flow resumes their waits). Watch the replies with
//   php artisan botcake:stress-page --stats
import { chat } from './common.js';

const step = (rate, start) => ({ executor: 'constant-arrival-rate', rate, timeUnit: '1s', duration: '30s', startTime: start, preAllocatedVUs: rate, maxVUs: rate * 4 });

export const options = {
  scenarios: {
    r10: step(10, '0s'), r20: step(20, '30s'), r30: step(30, '60s'), r50: step(50, '90s'), r100: step(100, '120s'),
  },
  thresholds: { http_req_failed: ['rate<0.01'] },
};

export default function () {
  chat(1000);
}
