/**
 * Playwright global setup.
 *
 * Waits for the Docker Compose stack to be ready before running tests.
 * Assumes the stack is already running (started by `make up` or CI).
 */
async function globalSetup() {
  const baseUrl = process.env.BASE_URL ?? 'http://localhost:47180';
  const maxRetries = 30;
  const retryDelay = 2000;

  console.log(`Waiting for stack at ${baseUrl}...`);

  for (let i = 0; i < maxRetries; i++) {
    try {
      const response = await fetch(`${baseUrl}/api/v2/health`);
      if (response.ok) {
        console.log('Stack is ready.');
        return;
      }
    } catch {
      // Not ready yet
    }

    if (i < maxRetries - 1) {
      console.log(`  Attempt ${i + 1}/${maxRetries} — waiting ${retryDelay / 1000}s...`);
      await new Promise((resolve) => setTimeout(resolve, retryDelay));
    }
  }

  throw new Error(`Stack at ${baseUrl} did not become ready within ${(maxRetries * retryDelay) / 1000}s`);
}

export default globalSetup;
