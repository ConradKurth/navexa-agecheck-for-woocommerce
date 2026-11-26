/**
 * Major search-engine crawlers the entry gate lets through (TAX-1844), so a
 * gated store stays indexed. Matched on the user agent only: a browser cannot
 * do the reverse-DNS check that proves a crawler, and the gate is a
 * self-confirmation anyway (anyone can click Yes), so a spoofed user agent
 * gains nothing it could not have by clicking.
 */
const CRAWLERS =
  /\b(googlebot|google-inspectiontool|storebot-google|adsbot-google|mediapartners-google|bingbot|adidxbot|bingpreview|duckduckbot|applebot|yandex(?:bot|images)|baiduspider|slurp)\b/i

export function isCrawler(userAgent: string): boolean {
  return CRAWLERS.test(userAgent)
}
