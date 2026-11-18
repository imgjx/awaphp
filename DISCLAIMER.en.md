# Disclaimer

[简体中文](DISCLAIMER.md) · **English**

> This document applies to **AWAPHP** (the "Tool") and all of its related code, documentation and outputs.
> Using the Tool means you have read, understood and accepted this disclaimer in full.

---

## 1. Purpose

The Tool is a **source-protection** tool. Its goal is to raise the cost of static reverse engineering and tampering of PHP code **without changing program semantics**, helping **rights holders** protect code they lawfully own (released under the **MIT licence**; see the `LICENSE` file in the repository root).

The Tool must only be used on PHP code that **you own or are authorised to handle**.

---

## 2. Lawful use

You agree **not** to use the Tool for any of the following (including but not limited to):

- obfuscating, packing or distributing any **virus, trojan, worm, ransomware, backdoor, spyware** or other malicious code;
- decompiling, obfuscating or redistributing **software to which a third party holds lawful rights**, infringing copyright, trade secrets or other intellectual property;
- circumventing, cracking or bypassing any software's **licence checks, licensing restrictions or technical protection measures**;
- any act that violates applicable laws and regulations, endangers network security, or infringes the lawful rights of others.

If your conduct is unlawful, **you alone bear all legal consequences**; the author of the Tool is not involved.

---

## 3. Technical limitations

Every protection the Tool provides (identifier obfuscation, control-flow obfuscation, string/number encoding, virtual machine protection, packing, anti-debug, anti-tamper, self-checks, …) **only raises the cost** of reverse engineering and tampering — it **does not provide absolute security**.

- Any obfuscation, packing or runtime protection can be **broken or reversed** by a third party with sufficient resources and skill, through dynamic debugging, binary analysis, manual reverse engineering, and so on;
- The Tool **does not guarantee** that obfuscated code resists every form of analysis, cracking or tampering;
- High-strength configurations may affect runtime performance, maintainability and debuggability; you judge those trade-offs yourself.

Do not rely on the Tool as the sole means of protecting sensitive keys, credentials or core business logic.

---

## 4. Risk and liability

- **Risk of use**: obfuscation performs complex transformations on code structure. **Back up your sources completely** before production use, and fully validate the output (`php -l` plus functional tests).
- **Data and security**: the author of the Tool **accepts no responsibility** for any data loss, business interruption, system failure, security incident or legal dispute arising from use of the Tool.
- **No warranty**: the Tool is provided **AS IS**, without warranty of any kind, express or implied, including but not limited to warranties of **merchantability, fitness for a particular purpose, non-infringement and freedom from defects**.
- **Limitation of liability**: in no event shall the author be liable for any direct, indirect, incidental, special or consequential damages arising from the use of, or inability to use, the Tool.

---

## 5. MIT licence

The Tool is released under the **MIT licence**; anyone may freely use, copy, modify, merge, publish, distribute, sublicense and sell copies of the software.

- Copyright and related rights in the Tool belong to its author, who grants the freedoms above under the MIT licence;
- Rights in the **output** produced by obfuscating your own code follow from the rights you hold in the original source; the Tool acquires and claims no rights in your code;
- Distributing the Tool or derivative works requires retaining the original copyright and licence notices (see the `LICENSE` file in the repository root);
- The software is provided **AS IS**, without warranty of any kind, express or implied, and the author accepts no liability for any consequences of its use.

> The full licence text is in the repository root `LICENSE` (MIT).

---

## 6. Miscellaneous

- If any provision of this disclaimer is held invalid or unenforceable, the remaining provisions stay in effect.
- This disclaimer is to be interpreted and applied in accordance with applicable laws. Where it conflicts with mandatory legal provisions, the law prevails.

---

*Last updated: v1.2.7*
