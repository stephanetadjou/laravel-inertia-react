// Base de connaissances de l’assistant, générée au build (export statique).
import { assistantKb } from '@/lib/assistant';

export const dynamic = 'force-static';

export function GET() {
  return Response.json(assistantKb);
}
