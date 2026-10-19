// Intake: steps the chat rate up to 100 per second (only the endpoint; the flow runs later on the chatbot queue).
import { chat } from './common.js';

export const options = {
  scenarios: {
    intake: {
      executor: 'ramping-arrival-rate', startRate: 10, timeUnit: '1s', preAllocatedVUs: 50, maxVUs: 300,
      stages: [
        { target: 10, duration: '30s' }, { target: 25, duration: '30s' }, { target: 50, duration: '30s' },
        { target: 75, duration: '30s' }, { target: 100, duration: '60s' },
      ],
    },
  },
  thresholds: { http_req_failed: ['rate<0.01'], http_req_duration: ['p(95)<1000'] },
};

export default function () {
  chat(100000);
}
