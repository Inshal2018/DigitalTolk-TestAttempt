# TestAttemp-DigitalTolk

Hello Everyone first of all thankyou for giving me oppurtunity to attemp this fun task. So I have attempted the test and I choose the option number 2 which is
**2. Readme described above (point X above) + refactored core + a unit test of the code that we have sent**

X. A readme with:   Your thoughts about the code. What makes it amazing code. Or what makes it ok code. Or what makes it terrible code. How would you have done it. Thoughts on formatting, structure, logic.. The more details that you can provide about the code (what's terrible about it or/and what is good about it) the easier for us to assess your coding style, mentality etc


So point X I will cover here in this readme.md:
My thoughts about this code I will categorize it:
**What makes it amazing code:**
This is not an amazing code but still there are few good points covered here 
- For example adding TODO notes to remember what needs to be done and where.
- Function names are descriptive but that is not applied on all the functions.
- I did not found any security issue with the code.

**What makes it a terrible code:** (Side note: No Code is terrible unless you cannot fix it)
- Code has no uniformity for example variable names sometimes they are defined snake_case sometimes in camelCase and sometimes not descriptive enough like cuser which should be currentUser as per the context.
- No error handling least we can do is to use try catch block and that's what I have done in refactoring as well.
- Missing Code Doc and proper return types.
- In case of API we should keep in mind that we need to return proper response code and proper data object. Preferred JSON (In REST APIs).
- Use of Curl instead of Framework HTTP Facade.
- Lots of IFs and ElseIf rather then switch case. 
- No use of Ternary or Null Coalescing Operator.
- Use of "Yes" or "No" string for conditions which can be simplified using boolean expretions.
- Missing/Not using early returns.

As mentioned in the Test Description Readme file I put as much love as I could in 4 hours though improvement can still be made in the code.
**What I did:**
- I changed no case and non descriptive variable names into camelCase and descriptive names **for example cuser to currentUser**.
- I put try catch block in the functions so that we do not get un usual response in the API response and if its a function with void or no response I add Log in the catch case.
- I simplified unneccessary IF conditions for assinging value using Ternary and Null Coalescing Operator.
- Set API response as JSON with response code.
- Defined Doc for the functions along with the return type.
- Simplified unneccesary IF conditions used Switch Case where applicable.
- Replace Curl Request with Laravel HTTP Facade 
- Simplified function by breaking into multiple for demonstrating Single Responsibility Principle.

I have put test case in the tests/Unit folder file name is UserRepositoryTest.php

Thank you for your time.
