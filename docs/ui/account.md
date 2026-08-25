---
description: "Your personal account, the teams you belong to, custom roles, company login, space blueprints, and the spaces overview."
---

# Account, teams, and spaces

Some settings sit above any single space: your own account, the teams you belong to, and the list of spaces you can open.

## Your account

- **Profile**: your name and profile picture. Your email address and your user identifier are shown but cannot be changed here.
- **Security**: change your password and set up **two-factor authentication**, which asks for a six-digit code from an app on your phone in addition to your password. When you switch it on you are shown a set of **recovery codes**. Save them somewhere safe. They are how you get back in if you ever lose the phone.
- **Personal access tokens**: passwords for programs rather than people. A script or another tool can use one to act on your behalf through the API. Treat them exactly like passwords, and delete the ones you no longer use.
- **Invitations**: invitations to spaces and teams that are waiting for you. Accept or decline them here.
- **Notifications**: which events you want to hear about in the app and by email.

## Teams

A **team** groups people and spaces, which is what larger organizations need. Instead of inviting every new colleague to fourteen spaces one by one, you add them to the team once and they get access to what their role allows.

- **Create a team**, invite people, and give each person a team role: *owner*, *admin*, or *member*. The role decides what they may do, for example whether they can create new spaces in the team.
- A team's spaces are visible to the team's members according to their role, so no per-space invitation is needed for every hire.
- Teams can contain teams, which helps larger organizations mirror how they are actually structured. A team inside another one inherits the parent's custom roles.

The team page has four tabs.

### People

Everybody in the team with their role, plus invitations that haven't been accepted yet, which you can resend or revoke. Removing somebody here removes the access they had through the team, in every space the team owns.

### Roles

::: tip Highlight
Roles are not a fixed list you have to live with. A team can define its own, with exactly the permissions it needs, and offer them across every space it owns. "Translator" or "Shop editor" becomes a real role rather than an agreement nobody wrote down.
:::

Alongside the built-in roles (*owner*, *admin*, *editor*, *member*, *billing*, and *viewer*), a team can define **its own roles** once and offer them in every space it owns. That is how "Translator" or "Shop editor" becomes a real role instead of a note in a wiki.

- A role has a name, a technical key, a **level**, and the list of **permissions** it grants. The level is a number from 1 to 299 that ranks the role against the others, and it decides who may hand out or edit which role. The built-in *owner* sits at 300, above everything you can define.
- The list marks each role *system*, *custom*, or *inherited*. Inherited roles come from a parent team and are managed there.
- Built-in roles cannot be changed or deleted. Your own roles can, and a change applies immediately to everybody who holds that role.

### Single sign-on (SAML)

This tab is for whoever administers your company's identity system. If that isn't you, you can skip it.

**Single sign-on** means people use their existing company account to get into b10cks, instead of a separate email-and-password login. The company's system, called the identity provider, vouches for who somebody is. SAML is the long-established standard for that conversation, and b10cks speaks it per team.

The screen shows the values to paste into your identity provider (the login, ACS, SLS, and metadata addresses, each specific to this team), and asks for the other side:

- **The identity provider's entity ID, sign-in address, optional sign-out address, and X.509 certificate.** The certificate can be pasted as PEM or as plain base64.
- **A certificate and private key for b10cks itself**, only needed if you want b10cks to sign its requests or receive encrypted assertions.
- **Attribute mapping**: which fields of the login message carry the email address (required), the first name, the last name, and the stable identifier for the person. The identifier defaults to the `NameID`.
- **Role mapping**: an attribute whose values decide the team role, plus a **default role** for everybody who doesn't match a rule.
- **Just-in-time provisioning**, which is on by default and means an account is created automatically the first time somebody signs in. Switch it off and only people who already have an account in the team may get in.
- **Security options.** Strict validation and requiring signed assertions cannot be turned off, because turning them off would make the login messages forgeable. Signing requests, requiring signed messages, and encrypting assertions are optional, as are the signature algorithms.

There is no single sign-on button on the login page. People start at the team's own login address, or their identity provider starts the process for them. An email address that already belongs to an account elsewhere on the installation is not silently claimed by your team: either that account is already a member through an invitation it accepted, or a new account is created.

### Settings

The team's name, description, type, avatar, and its position in the team hierarchy.

Access to a single space, on top of what the team grants, is managed in that space under **Settings → People** ([Space settings](settings.md#people)).

## Space blueprints

::: tip Highlight
For anybody who builds more than one site, this is the time-saver: a blueprint captures a finished content model, folder structure, tags, and settings, and every new space starts from it. Twelve projects a year stop beginning with the same two days of setup.
:::

A **blueprint** is a reusable starting point for new spaces: the structure without the content. If your agency builds twelve sites a year that all share the same twenty content types, you set that up once and every new space starts complete instead of empty.

- Create one from the action menu of an existing space, with a name, an icon, and a colour, and choose what to include: content types, their folders and tags, block templates, media folders and tags, and data sets.
- The blueprint is filed under a team, and the space you copy from has to belong to that same team.
- A blueprint that belongs to a team can be used by everybody in that team. Administrators can also create one for the whole installation, which offers it to everyone.
- **Settings are copied too**, such as languages, editor defaults, and the media fields. Anything you set while creating the new space wins over what the blueprint brought along.
- Pages and files are never part of a blueprint. To move actual content between spaces, use a [migration](settings.md#migrations).

## The spaces overview

The screen you land on after signing in lists every space you can open, whether you were invited directly or belong to a team that owns it. New spaces are created from here, with a name, a team, and, on installations that bill, a plan. What a space actually contains is described in [Spaces](../concepts/spaces.md).
