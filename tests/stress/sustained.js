// Sustained: 100 chats per second for 5 minutes.
import { chat } from './common.js';

export const options = {
  scenarios: {
    sustained: { executor: 'constant-arrival-rate', rate: 100, timeUnit: '1s', duration: '5m', preAllocatedVUs: 100, maxVUs: 400 },
  },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1000'] },
};

export default function () {
  chat(10000);
}
