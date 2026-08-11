## 2024-08-11 - Extract JSON Parsing Logic

**Context:** `AIPS_AI_Service` was acting as a "God Object" by handling AI provider orchestration, resilience, rate limiting, *and* raw text manipulation/JSON parsing.

**Decision:** Created a new utility class, `AIPS_JSON_Extractor`, to handle extracting and sanitizing JSON from AI responses. This adheres to "Separation of Concerns" and "Single Responsibility" principles.

**Consequence:** A new class is introduced to the autoloader. The AI service is now decoupled from the specifics of JSON string manipulation, making it cleaner.

**Tests:** Verified the existing test suite continues to pass, ensuring that the new decoupled architecture behaves identically to the old one with 100% backward compatibility.
