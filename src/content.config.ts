// Konfiguracja treści strony.
// Każdy artykuł to plik .md w src/content/blog — tutaj opisujemy, jakie pola musi mieć.
// Jeśli w artykule zabraknie wymaganego pola (np. tytułu), Astro pokaże błąd przed publikacją.
import { defineCollection, z } from "astro:content";
import { glob } from "astro/loaders";

const blog = defineCollection({
  loader: glob({ pattern: "**/*.md", base: "./src/content/blog" }),
  schema: ({ image }) =>
    z.object({
      title: z.string(),
      description: z.string(), // krótki opis: pod tytułem i w wynikach Google
      date: z.coerce.date(), // data publikacji, np. 2026-11-04
      updated: z.coerce.date().optional(), // data aktualizacji (ważne przy przepisach)
      category: z.string(), // np. KSeF, Podatki, Poradnik
      author: z.object({
        name: z.string(),
        role: z.string(),
        bio: z.string().optional(),
      }),
      cover: image().optional(), // zdjęcie okładki (plik obok artykułu); bez niego pokaże się grafika z logo
      coverAlt: z.string().optional(),
      coverPosition: z.string().optional(), // kadrowanie okładki, np. "center 30%" — gdy ważna część zdjęcia jest wyżej/niżej
      summary: z.array(z.string()).optional(), // punkty do ramki „W skrócie”
      draft: z.boolean().default(false), // true = szkic, nie trafia na stronę
    }),
});

export const collections = { blog };