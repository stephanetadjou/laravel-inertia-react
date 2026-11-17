/** @type {import('next').NextConfig} */
const nextConfig = {
  // Site 100 % statique : déployé sur Azure Static Web Apps.
  output: 'export',
  trailingSlash: true, // chaque page = dossier/index.html : marche chez tous les hébergeurs
  images: { unoptimized: true },
  // Aperçu sous un sous-dossier (ex. /site sur le serveur de test) : NEXT_BASE_PATH=/site npm run build
  basePath: process.env.NEXT_BASE_PATH || '',
};
export default nextConfig;
