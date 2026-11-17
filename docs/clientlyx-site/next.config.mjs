/** @type {import('next').NextConfig} */
const nextConfig = {
  // Site 100 % statique : déployé sur Azure Static Web Apps.
  output: 'export',
  trailingSlash: true, // chaque page = dossier/index.html : marche chez tous les hébergeurs
  images: { unoptimized: true },
};
export default nextConfig;
