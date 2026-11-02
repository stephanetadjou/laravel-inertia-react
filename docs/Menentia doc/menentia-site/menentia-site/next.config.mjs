/** @type {import('next').NextConfig} */
const nextConfig = {
  // Site 100 % statique : déployé sur Azure Static Web Apps.
  output: 'export',
  trailingSlash: false,
  images: { unoptimized: true },
};
export default nextConfig;
