// @ts-check
// Zentrale Umgebungs-Defaults. Die Testkonten legt ../../../dev.sh up bzw. setup an.
module.exports = {
	BASE_URL: process.env.BASE_URL || 'http://host.docker.internal:8092',
	E2E_USER: process.env.E2E_USER || 'e2e',
	E2E_OTHER_USER: process.env.E2E_OTHER_USER || 'e2e-other',
	E2E_PASSWORD: process.env.E2E_PASSWORD || 'e2e-test-Pass123!',
}
