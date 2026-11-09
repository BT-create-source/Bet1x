# Originals attribution

Dragon Tiger's gameplay reference is [UP-MSIT/dragon-tiger-game](https://github.com/UP-MSIT/dragon-tiger-game), inspected at commit `02717e25d020cbff47792de03e6bd520ff654441`. That repository is a Python/Tkinter desktop game and has no license file. This site contains an independent PHP/JavaScript implementation and original generated cover artwork; no upstream source or images are included. Rank order (A low, K high), independent card draws, and half-stake returns on tied Dragon/Tiger bets follow the reference. Site payout terms are explicit: winning main bets return 2x, winning Tie bets return 12x (including stake), replacing the reference's 8x Tie return. RTP is 25/26 on main bets and 12/13 on Tie before rounding and return caps. The game uses the existing Originals wallet, seeds, ledger, operator limits, history, and universal betting window.

The game selection and visual/mechanics references came from [Paidguy/casino](https://github.com/Paidguy/casino). The bet1x integration uses its own PHP rules, wallet transactions and theme-matched frontend rather than the source project's browser wallet. Original bet1x cover artwork was generated with the built-in image generation tool and is served as compressed WebP. The prompts are retained in `docs/originals-thumbnail-prompts.json`.

Upstream license: https://github.com/Paidguy/casino/blob/main/LICENSE

MIT License

Copyright (c) 2025 Maharaja Exchange Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
