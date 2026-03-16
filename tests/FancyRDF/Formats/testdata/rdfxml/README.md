This folder contains local testdata for rdf/xml. 

It contains several testcases, one per folder.
Each folder contains three files:
- `$testcase.nt`: Triple dataset in N-Triples format
- `$testcase.xml`: Dataset which should de-serialize into the triple dataset
- `$testcase-serialized.xml`: Dataset the serializer should output for N-Triples.

XML output is to be compared under [C14N](https://www.w3.org/TR/xml-exc-c14n/) normalization.
Triples are compared under (literal) dataset equivalence.